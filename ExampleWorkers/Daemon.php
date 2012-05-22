<?php

/**
 * This Daemon has been created to demonstrate the Workers API in 2.0.
 *
 * It creates two workers: a simple closure-based worker that computers factors, and
 * an object-based Prime Numbers worker.
 *
 * It runs jobs randomly and in response to signals and writes the jobs in a log to the MySQL table
 * described in the db.sql file.
 *
 */
class ExampleWorkers_Daemon extends Core_Daemon
{
    protected $loop_interval = 1;

    /**
     * We want to be able to start workers by passing in signals. In a real daemon, workers would be used to process
     * input, handle complex events, etc. To simulate that we're adding listeners for various signals that we can call as desired from the commandline.
     * @var bool
     */
    public $run_primes_among = false;
    public $run_sieve        = false;
    public $run_getfactors   = false;
    public $auto_run         = false;

    /**
     * @var Resource
     */
    public $db;

    protected function load_plugins()
    {
        $this->plugin('Lock_File');

        // This daemon will respond to signals sent from the commandline.
        // 1) You can send a signal that will calculate factors of a random number
        // 2) You can send a signal that will find primes within a random range.
        // 3) You can send a signal that will turn auto_run on and off. When it's on, the execute() method will randomly start worker jobs to mimic event-driven behavior.
        // The signals themselves are defined in the settings.ini
        // We also have other various settings defined in the ini, so we validate that the ini has both [signals] and [default] section
        // We're using the INI file here only because it's a conveinient way to demonstrate using the INI plugin.

        $this->plugin('settings', new Core_Plugin_Ini());
        $this->settings->filename = BASE_PATH . '/ExampleWorkers/settings.ini';
        $this->settings->required_sections = array('signals', 'default');
    }

    protected function load_workers()
    {
        // PHP 5.3 Closure Hack. Fixed in 5.4.
        $that = $this;

        // Instantiate an App_Primes object as a Worker
        // - Load 4 workers in the pool
        // - Allocate 50MB of shared memory to pass args to the workers and receive results back: If you omit this, it will use 5MB by default.
        //
        //      It's VERY important to allocate enough shared memory: You should allocate enough memory that when a job returns it uses no more than 2%
        //      of the allocation. Remember: When a call returns, the struct that is passed to your onReturn method contains the arguments passed to the worker
        //      AND the return value. If both are large, you could easily end up needing 50, 100, 200MB of shared memory.
        //      If you allocate to little memory, a WARNING will be logged to the Event log: Keep an eye out for it during your development process.
        //
        // - By convention, workers are named in UpperCase
        // - Look at App_Prime to see the available methods. They are: sieve, is_prime, primes_among

        $this->worker('PrimeNumbers', new ExampleWorkers_Workers_Primes());
        $this->PrimeNumbers->timeout(60);
        $this->PrimeNumbers->workers(4);
        $this->PrimeNumbers->malloc(30 * 1024 * 1024);

        $this->PrimeNumbers->onReturn(function($call, $log) use($that) {

            // Log the results of the call to the event log
            $log("Job {$call->id} to {$call->method}() Complete");
            switch($call->method) {
                case "sieve":
                    $log(sprintf('Return: There are %s items in the resultset, from %s to %s.', count($call->return), $call->return[0], $call->return[count($call->return)-1])  );
                    break;

                case "primes_among":
                    $log(sprintf('Return. Among [%s], Primes Are [%s]', implode(', ', $call->args[0]), implode(', ', $call->return)));
            }

            // Update MySQL with the results of this call
            $that->job_return($call);
        });

        $this->PrimeNumbers->onTimeout(function($call, $log) use($that) {
            $log("Job {$call->id} Timed Out!");
            if ($call->retries < 3) {
                $that->PrimeNumbers->retry($call);
            } else {
                $log("Retries Concluded. I Give Up.");
            }

            $that->job_timeout($call);
        });


        // Add a GetFactors Function as a Named Worker
        // - It will accept a single integer and return all of its factors.
        // - Load 2 workers in the pool
        // - Leave the memory allocation at the default: We will not be passing very much data back-and-forth.
        // - In the Return handler, we are using the PrimeNumbers worker to determine the prime factors.
        $this->worker('GetFactors', function($integer)  {
            if (!is_integer($integer))
                throw new Exception('Invalid Input! Expected Integer. Given: ' . gettype($integer));

            $factors = array();
            for ($i=2; $i<($integer/2); $i++)
                if ($integer % $i == 0)
                    $factors[] = $i;

            return $factors;
        });

        $this->GetFactors->timeout(60);
        $this->GetFactors->workers(2);
        $this->GetFactors->onReturn(function($call, $log) use($that) {
            $log(sprintf('Return: %s has %s factors', $call->args[0], count($call->return)));

            if (count($call->return)) {
                $log("Finding Prime Factors");
                $job = $that->PrimeNumbers->primes_among($call->return);
                if ($job)
                    $sql = sprintf('INSERT INTO jobs (pid, job, worker) values(%s, %s, "%s")', $that->pid(), $job, 'primes_among');
                else
                    $log("Call Failed");

                if (false == mysqli_query($that->db, $sql))
                    $that->reconnect_db($sql);
            }

            $that->job_return($call);
        });

        $this->GetFactors->onTimeout(function($call, $log) use($that) {
            $that->job_timeout($call);
        });
    }


    protected function setup()
    {
        if ($this->is_parent())
        {
            // We want to be able use signals to interact with the example daemon, so we can test and demonstrate
            // the workers. Load a configurable signal map from the loaded ini plugin

            $that = $this;
            $this->on(Core_Daemon::ON_SIGNAL, function($signal) use($that) {
                if (isset($that->settings['signals'][$signal])) {
                    $action = $that->settings['signals'][$signal];

                    if ($action == 'auto_run') {
                        $that->log("Signal Received! Setting auto_run=" . ($that->auto_run ? 'false' : 'true'));
                        $that->{$action} = !$that->{$action};
                    } else {
                        $that->log("Signal Received! Setting {$action}=true");
                        $that->{$action} = true;
                    }
                }
            });

            // You never want to call a worker method directly from a signal handler.
            // This is because signal handlers are not re-entrant. So worker processes forked within a signal handler
            // will not respond to any signals themselves. So here we're setting a flag that is polled in the execute() method.

            // Connect to the DB
            $this->db = mysqli_connect('localhost', 'root', 'root');
            mysqli_select_db($this->db, 'daemon');

            $this->log("ExampleWorkers Daemon is Ready: To run a Factoring job, send signal 12. To run a Prime Numbers job, send signal 13. To toggle the random job-runner send signal 14.");
        }
    }


    protected function execute()
    {
        // Run our Factor and Prime workers randomly and in response to signals
        switch (mt_rand(1, 20)) {
            case 2:
            case 3:
            case 4:
            case 5:
            case 6:
                $this->run_getfactors = $this->auto_run;
                break;

            case 7:
                $this->run_getfactors = $this->auto_run;

            case 8:
                $this->run_sieve = $this->auto_run;
                break;
        }

        if ($this->run_getfactors) {
            $this->run_getfactors = false;

            // Pick a random number to factor. The call will return an ID we can use, later, if we want, to check the status of the call
            $rand = mt_rand(500000, 10000000);
            $job = $this->GetFactors($rand);

            if ($job)
                $sql = sprintf('INSERT INTO jobs (pid, job, worker) values(%s, %s, "%s")', $this->pid(), $job, 'execute');
            else
                $this->log("Job Failed.");

            if ($sql)
                if (false == mysqli_query($this->db, $sql))
                    $this->reconnect_db($sql);

            unset($sql);
        }

        if ($this->run_sieve) {
            $this->run_sieve = false;

            // Same Thing as we do for GetFactors
            $rand = mt_rand(10000, 1000000);
            $job = $this->PrimeNumbers->sieve($rand, $rand + $rand);

            if ($job)
                $sql = sprintf('INSERT INTO jobs (pid, job, worker) values(%s, %s, "%s")', $this->pid(), $job, 'sieve');
            else
                $this->log("Job Failed.");

            if (isset($sql))
                if (false == mysqli_query($this->db, $sql))
                    $this->reconnect_db($sql);

            unset($sql);
        }
    }

    /**
     * Update the database record for the job specified by the $call struct
     * Intended to be used in an onReturn callback, which is called by the Worker and passed an object w/
     * all the call datails
     *
     * @param stdClass $call
     * @return void
     */
    public function job_return(stdClass $call) {
        $sql = sprintf('UPDATE jobs set is_complete=1, completed_at=NOW() where pid=%s and worker="%s" and job=%s', $this->pid(), $call->method, $call->id);
        if (false == mysqli_query($this->db, $sql))
            $this->reconnect_db($sql);
    }

    /**
     * Update the database record for the job specified by the $call struct
     * Intended to be used in an onTimeout callback, which is called by the Worker and passed an object w/
     * all the call datails
     *
     * @param stdClass $call
     * @return void
     */
    public function job_timeout(stdClass $call) {
        $sql = sprintf('UPDATE jobs set is_timeout=1, retries=%s, completed_at=NOW() where pid=%s and worker="%s" and job=%s',
                        $call->retries, $this->pid(), $call->method, $call->id);
        if (false == mysqli_query($this->db, $sql))
            $this->reconnect_db($sql);
    }

    public function reconnect_db($sql) {
        usleep(25000);
        $this->db = mysqli_connect('localhost', 'root', 'root');
        mysqli_select_db($this->db, 'daemon');
        if (!mysqli_query($this->db, $sql))
            $this->log(mysqli_error($this->db));
    }

    protected function log_file()
    {
        $dir = '/var/log/daemons/exampleworkers';
        if (@file_exists($dir) == false)
            @mkdir($dir, 0777, true);

        if (@is_writable($dir) == false)
            $dir = BASE_PATH . '/logs';

        return $dir . '/log_' . date('Ymd');
    }
}