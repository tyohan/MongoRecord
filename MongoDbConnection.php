<?php

/**
 * This is a MongoDbConnection component that used for connect to mongoDb database
 *
 * @author yohan
 */
class MongoDbConnection extends CApplicationComponent
{
    private $_dbConnection;
    private $_db;
    private $_user;
    private $_password;
    private $_host='localhost';
    
    public function  init()
    {
        parent::init();
        
    }

    protected function getConnection()
    {
        if($this->_dbConnection===NULL)
        {
            try
            {
                $this->_dbConnection= new Mongo($this->_host);
                if($this->_user!==NULL && $this->_password!==NULL)
                         $this->_dbConnection->$db->authenticate($this->_user, $this->_password);
                    
            }
            catch(MongoConnectionException $e)
            {
                throw new CDbException("Can't connect to Mongo DB");
            }
        }
        return $this->_dbConnection;
    }

    protected function setDb($config)
    {
        $this->_db=$config;
    }
    protected function getDb()
    {
        $db=$this->_db;
        return $this->connection->$db;
    }
    
    protected function setUser($config)
    {
        $this->_user=$config;
    }
    protected function getUser()
    {
        return $this->_user;
    }
    protected function setPassword($config)
    {
        $this->_password=$config;
    }
    protected function getPassword()
    {
        return $this->_password;
    }

    protected function setHost($config)
    {
        $this->_host=$config;
    }
    protected function getHost()
    {
        return $this->_host;
    }
}