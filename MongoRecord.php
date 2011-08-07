<?php

/**
 * Description of MongoRecord
 *
 * @author yohan
 */
abstract  class MongoRecord extends CModel
{
    public static $db;
    public $safe=TRUE;
    public $fsync=TRUE;
    private $_sort;
    private $_id;
    private $_new;
    public $limit=0;
    public $skip=0;


    /**
     * $_document property to store MongoDB document. Must defined as array in each MongoRecord model.
     */
    protected $_document;
    
    private static $_models=array();
    
    public function getDb()
    {
        return Yii::app()->getComponent('mongodb')->db;
    }
    
    
  
    /**
     * Convert collection record from array multi level to flat array.
     * Record will only flatted when the array key is not numeric key
     * For flatted value it's seperated with "." to identified it's parent
     */
    protected function parseAttributes($attributes,$prefix='')
    {
        $flatAttributes=array();
        foreach($attributes as $key=>$value)
        {
            if(is_numeric($key) && is_array($value))
                $flatAttributes=array_merge($flatAttributes, $this->parseAttributes($value,$key.'.'));
             else
                $flatAttributes[$key]=$value;
        }
        return $flatAttributes;
    }
    
    public function __construct($arg='insert')
    {
            if($arg===null) // internally used by populateRecord() and model()
                    return;
            elseif(is_array($arg))
            {
                $this->_document=$arg;
                return;
            }

            $this->setScenario($arg);
            $this->setIsNewRecord(true);

            //$this->init();

            $this->attachBehaviors($this->behaviors());
            $this->afterConstruct();
    }

   /**
     * PHP getter magic method.
     * This method is overridden so that MongoDB document can be accessed like properties.
     * @param string property name
     * @return mixed property value
     * @see getAttribute
     */
    public function __get($name)
    {
            if($name==='id')
                return $this->_document['_id'];
            elseif(array_key_exists($name,$this->_document))
                return $this->_document[$name];
            else
                return parent::__get($name);
    }

    /**
     * PHP setter magic method.
     * This method is overridden so that MongoDB document can be accessed like properties.
     * @param string property name
     * @param mixed property value
     */
    public function __set($name,$value)
    {
        if(array_key_exists($name,$this->_document))
                $this->_document[$name]=$value;
        else
                parent::__set($name,$value);
    }

    public function getAttributes($names=true)
    {
            $doc=$this->_document;
            if(is_array($names))
            {
                    $attrs=array();
                    foreach($names as $name)
                    {
                            if(property_exists($this,$name))
                                    $attrs[$name]=$this->$name;
                            else
                                    $attrs[$name]=isset($doc[$name])?$doc[$name]:null;
                    }
                    return $attrs;
            }
            else
                    return $doc;
    }
    
    abstract protected function getCollectionName();
    
    public function getCollection()
    {
        return $this->db->{$this->collectionName};
    }
   
    public function getIsNewRecord()
    {
            if(!isset($this->_document['_id']))
                return TRUE;
            
           return (!$this->id instanceof  MongoId)?TRUE:FALSE;

    }
    public function save($runValidation=true,$attributes=null)
    {
            if(!$runValidation || $this->validate($this->attributes))
            {
                    return $this->getIsNewRecord() ? $this->insert() : $this->update();
            }
            else
                    return false;
    }
    
    /**
     * Save document in $this->_document to database
     */
    public function insert()
    {   
        if($this->beforeSave())
        {
            try
            {
                $insert=$this->collection->insert($this->_document,array('fsync'=>$this->fsync,'safe'=>$this->safe));
                $this->_id=$this->_document['_id'];
                $this->afterSave();
                return TRUE;
            }
            catch (MongoCursorException $e)
            {
                throw new CDbException($e->getMessage(), $e->getCode());
                return FALSE;
            }
        }
        return FALSE;
        
        
    }
    
    /**
     * Update record in database with data from document
     * @return boolean whether record is succesfully updated or not
     */
    public function update()
    {
       if($this->getIsNewRecord())
            throw new CDbException(Yii::t('yii','The active record cannot be updated because it is new.'));
       if($this->beforeSave())
       {
           $doc=$this->_document;
           unset ($doc['_id']);
           try
           {
               $update=$this->collection->update(array('_id'=>new MongoId($this->id)),array('$set'=>$doc),array('fsync'=>$this->fsync,'multiple'=>FALSE));
               $this->afterSave();
               return TRUE;
           }
           catch (MongoCursorException $e)
           {
               throw new CDbException($e->getMessage(), $e->getCode());
               return FALSE;
           }
       }
       return FALSE;
    }
    
    /**
     * Update record in database with data from document
     * @return boolean whether record is succesfully updated or not
     */
    public function updateAll($criteria,$document)
    {
       return $this->collection->update($criteria,array('$set' => $document),array('safe'=>$this->safe,'fsync'=>$this->fsync,'multiple'=>TRUE));
    }
    
    /**
     * Remove record from database
     * @return boolean whether record is succesfully removed or not
     */
    public function delete()
    {
        if(!$this->getIsNewRecord())
        {
                Yii::trace(get_class($this).'.delete()','system.db.ar.CActiveRecord');
                if($this->beforeDelete())
                {
                        $result=$this->deleteById($this->id);
                        $this->afterDelete();
                        return $result;
                }
                else
                        return false;
        }
        else
                throw new CDbException(Yii::t('yii','The active record cannot be deleted because it is new.'));
    }
    
    /**
     * Remove all record that match with criteria from database
     * @param array record criteria to remove
     * @return int return number of deleted record
     */
    public function deleteAll($criteria)
    {
        $result=$this->collection->remove($criteria,array('justOne'=>FALSE,'safe'=>$this->safe));
        if(is_array($result) && isset($result['n']))
            return $result['n'];
        else
            return TRUE;
            
    }
    
    /**
     * Remove record with specific $id from database
     * @param string record id to remove
     * @return boolean whether record is succesfully removed or not
     */
    public function deleteById($id)
    {
        $result= $this->collection->remove(array('_id'=>new MongoId($id)),array('justOne'=>TRUE,'safe'=>$this->safe));
        if(is_array($result) && isset($result['ok']))
            return $result['ok'];
        else
            return TRUE;
    }
    
    /**
     * Refresh record by repull data from database
     */
    public function refresh()
    {
            Yii::trace(get_class($this).'.refresh()','system.db.ar.CActiveRecord');
            if(!$this->getIsNewRecord() && ($record=$this->findByid($this->_id))!==null)
            {
                    return true;
            }
            else
                    return false;
    }
    
    /**
     * Merge attributes with document
     */
    public function saveAttributes($attributes=array())
    {
        $this->_document=array_merge($this->_document,$attributes);
    }
    
    /**
     * @param boolean whether the record is new and should be inserted when calling {@link save}.
     * @see getIsNewRecord
     */
    public function setIsNewRecord($value)
    {
            $this->_new=$value;
    }

    /**
     * This event is raised before the record is saved.
     * @param CEvent the event parameter
     * @since 1.0.2
     */
    public function onBeforeSave($event)
    {
            $this->raiseEvent('onBeforeSave',$event);
    }

    /**
     * This event is raised after the record is saved.
     * @param CEvent the event parameter
     * @since 1.0.2
     */
    public function onAfterSave($event)
    {
            $this->raiseEvent('onAfterSave',$event);
    }

    /**
     * This event is raised before the record is deleted.
     * @param CEvent the event parameter
     * @since 1.0.2
     */
    public function onBeforeDelete($event)
    {
            $this->raiseEvent('onBeforeDelete',$event);
    }

    /**
     * This event is raised after the record is deleted.
     * @param CEvent the event parameter
     * @since 1.0.2
     */
    public function onAfterDelete($event)
    {
            $this->raiseEvent('onAfterDelete',$event);
    }

    /**
     * This event is raised after the record instance is created by new operator.
     * @param CEvent the event parameter
     * @since 1.0.2
     */
    public function onAfterConstruct($event)
    {
            $this->raiseEvent('onAfterConstruct',$event);
    }

    /**
     * This event is raised before an AR finder performs a find call.
     * @param CEvent the event parameter
     * @see beforeFind
     * @since 1.0.9
     */
    public function onBeforeFind($event)
    {
            $this->raiseEvent('onBeforeFind',$event);
    }

    /**
     * This event is raised after the record is instantiated by a find method.
     * @param CEvent the event parameter
     * @since 1.0.2
     */
    public function onAfterFind($event)
    {
            $this->raiseEvent('onAfterFind',$event);
    }

    /**
     * This method is invoked before saving a record (after validation, if any).
     * The default implementation raises the {@link onBeforeSave} event.
     * You may override this method to do any preparation work for record saving.
     * Use {@link isNewRecord} to determine whether the saving is
     * for inserting or updating record.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @return boolean whether the saving should be executed. Defaults to true.
     */
    protected function beforeSave()
    {
            if($this->hasEventHandler('onBeforeSave'))
            {
                    $event=new CModelEvent($this);
                    $this->onBeforeSave($event);
                    return $event->isValid;
            }
            else
                    return true;
    }

    /**
     * This method is invoked after saving a record successfully.
     * The default implementation raises the {@link onAfterSave} event.
     * You may override this method to do postprocessing after record saving.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterSave()
    {
            if($this->hasEventHandler('onAfterSave'))
                    $this->onAfterSave(new CEvent($this));
    }

    /**
     * This method is invoked before deleting a record.
     * The default implementation raises the {@link onBeforeDelete} event.
     * You may override this method to do any preparation work for record deletion.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @return boolean whether the record should be deleted. Defaults to true.
     */
    protected function beforeDelete()
    {
            if($this->hasEventHandler('onBeforeDelete'))
            {
                    $event=new CModelEvent($this);
                    $this->onBeforeDelete($event);
                    return $event->isValid;
            }
            else
                    return true;
    }

    /**
     * This method is invoked after deleting a record.
     * The default implementation raises the {@link onAfterDelete} event.
     * You may override this method to do postprocessing after the record is deleted.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterDelete()
    {
            if($this->hasEventHandler('onAfterDelete'))
                    $this->onAfterDelete(new CEvent($this));
    }

    /**
     * This method is invoked after a record instance is created by new operator.
     * The default implementation raises the {@link onAfterConstruct} event.
     * You may override this method to do postprocessing after record creation.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterConstruct()
    {
            if($this->hasEventHandler('onAfterConstruct'))
                    $this->onAfterConstruct(new CEvent($this));
    }
    /**
     * Creates an active record instance.
     * This method is called by {@link populateRecord} and {@link populateRecords}.
     * You may override this method if the instance being created
     * depends the attributes that are to be populated to the record.
     * For example, by creating a record based on the value of a column,
     * you may implement the so-called single-table inheritance mapping.
     * @param array list of attribute values for the active records.
     * @return CActiveRecord the active record
     * @since 1.0.2
     */
    protected function instantiate($document)
    {
            $class=get_class($this);
            $model=new $class(null);
            $model->_document=array_merge($this->_document,$document);
            $model->afterFind();
            return $model;
    }
    /**
     * This method is invoked before an AR finder executes a find call.
     * The find calls include {@link find}, {@link findAll}, {@link findByPk},
     * {@link findAllByPk}, {@link findByAttributes} and {@link findAllByAttributes}.
     * The default implementation raises the {@link onBeforeFind} event.
     * If you override this method, make sure you call the parent implementation
     * so that the event is raised properly.
     * @since 1.0.9
     */
    protected function beforeFind()
    {
            if($this->hasEventHandler('onBeforeFind'))
                    $this->onBeforeFind(new CEvent($this));
    }

    /**
     * This method is invoked after each record is instantiated by a find method.
     * The default implementation raises the {@link onAfterFind} event.
     * You may override this method to do postprocessing after each newly found record is instantiated.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    protected function afterFind()
    {
            if($this->hasEventHandler('onAfterFind'))
                    $this->onAfterFind(new CEvent($this));
    }
    public function find($query=array())
    {
        $doc=$this->collection->findOne($query);

        if($doc!==NULL)
            return $this->instantiate($doc);
        else
            return NULL;
    }
    
    public function count($query=array())
    {
        return $this->collection->count($query,  $this->limit,$this->skip);
    }
    public function findById($id)
    {
        return $this->find(array('_id'=>new MongoId($id)));
    }
    public function limit($limit)
    {
        $this->limit=$limit;
        return $this;
    }
    public function skip($skip)
    {
        $this->skip=$skip;
        return $this;
    }
    
    public function findAll($criteria=array())
    {
        if(isset($criteria['query']))
            $docs=$this->collection->find($criteria['query']);
        else
            $docs=$this->collection->find($criteria);
        
        if(isset($criteria['sort']))
            $docs=$docs->sort($criteria['sort']);
        else if(!empty($this->_sort))
            $docs=$docs->sort($this->_sort);
        
        if(isset($criteria['limit']) || $this->limit>0)
            $docs=$docs->limit(isset($criteria['limit'])?$criteria['limit']:$this->limit);
        if(isset($criteria['skip']) || $this->skip>0)
            $docs=$docs->skip(isset($criteria['skip'])?$criteria['skip']:$this->skip);
        
        return $this->populateRecords($docs);

    }

    protected function populateRecords($documents)
    {
        $records=array();
        foreach($documents as $doc)
        {
            $records[]=$this->instantiate($doc);
        }
        return $records;
    }

    public static function model($className=__CLASS__)
    {
            if(isset(self::$_models[$className]))
                    return self::$_models[$className];
            else
            {
                    $model=self::$_models[$className]=new $className(null);
                    $model->attachBehaviors($model->behaviors());
                    return $model;
            }
    }

    public function sort($field=array())
    {
        $this->_sort=$field;
        return $this;
    }
}
