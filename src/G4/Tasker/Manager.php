<?php
namespace G4\Tasker;

use G4\Tasker\Model\Mapper\Mysql\Task as TaskMapper;

class Manager extends TimerAbstract
{
    const TIME_FORMAT = 'Y-m-d H:i:s';

    private $_tasks;

    private $_options;

    private $_runner;

    private $_limit;

    private $_identifier;

    public function __construct()
    {
        $this->_timerStart();

        $this->_limit = Consts::LIMIT_DEFAULT;
    }

    public function getIdentifier()
    {
        if (!isset($this->_identifier)) {
            $this->_generateIdentifier();
        }
        return $this->_identifier;
    }

    public function run()
    {
        $this
            ->_reserveTasks()
            ->_getTasks()
            ->_runTasks();
    }

    private function _getTasks()
    {
        $mapper = new TaskMapper();

        $identity = $mapper->getIdentity()
            ->field('identifier')
            ->eq($this->getIdentifier())
            ->field('status')
            ->eq(Consts::STATUS_PENDING);

        $this->_tasks = $mapper->findAll($identity);

        return $this;
    }

    private function _reserveTasks()
    {
        $mapper = new TaskMapper;

        $identity = $mapper->getIdentity()
            ->field('identifier')
            ->eq('')
            ->field('status')
            ->eq(Consts::STATUS_PENDING)
            ->field('created_ts')
            ->le(time())
            ->setOrderBy('priority', 'DESC')
            ->setLimit($this->_limit);

        $mapper
            ->updateAll($identity, array('identifier' => $this->getIdentifier()));

        return $this;
    }

    private function _runTasks()
    {
        if($this->_tasks->count() > 0) {
            $forker = new Forker();
            $forker->setRunner($this->getRunner());

            $mapper = new TaskMapper;

            foreach ($this->_tasks as $task) {
                $task->addMapper($mapper);

                // begin transaction
                $mapper->transactionBegin();

                // mark task as working
                $task->setStatus(Consts::STATUS_WORKING);
                $task->save();

                $this->addOption('id', $task->getId());

                try {
                    $forker
                        ->setOptions($this->getOptions())
                        ->fork();
                } catch (\Exception $e) {
                    // rollback
                    $mapper->transactionRollback();
                    // log message here
                    continue;
                }

                // commit
                $mapper->transactionCommit();
            }
        }

        $this
            ->_timerStop()
            ->_writeLog();
    }

    private function _writeLog()
    {
        echo "Started: " . date(self::TIME_FORMAT, $this->_getTimerStart()) . "\n";
        echo "Execution time: " . ($this->_getTotalTime()) . "\n";
    }

    public function getRunner()
    {
        return $this->_runner;
    }

    public function setRunner($value)
    {
        $this->_runner = $value;
        return $this;
    }

    public function getOptions()
    {
        return $this->_options;
    }

    public function setOptions(array $value)
    {
        $this->_options = $value;
        return $this;
    }

    public function addOption($key, $value)
    {
        $this->_options[$key] = $value;
        return $this;
    }

    public function getLimit()
    {
        return $this->_limit;
    }

    public function setLimit($value)
    {
        $this->_limit = $value;
        return $this;
    }

    private function _generateIdentifier()
    {
        $this->_identifier = gethostname()."|".time();
        return $this;
    }
}
