<?php
class Magmi_DefaultAttributeItemProcessor extends Magmi_ItemProcessor
{
	protected $_basecols=array("store"=>"admin","type"=>"simple");
	protected $_baseattrs=array("status"=>1,"visibility"=>4,"page_layout"=>"");
	protected $_forcedefault=array("store"=>"admin");
	protected $_missingcols=array();
	protected $_missingattrs=array();
	
	public function initialize($params)
	{
		$this->registerAttributeHandler($this,array("attribute_code:.*"));
	}
	
	public function getPluginInfo()
	{
		return array(
            "name" => "Standard Attribute Import",
            "author" => "Dweeves",
            "version" => "1.0.6"
            );
	}
	
	public function processColumnList(&$cols)
	{	
		$this->_missingcols=array_diff(array_keys($this->_basecols),$cols);
		$this->_missingattrs=array_diff(array_keys($this->_baseattrs),$cols);
		$m=$this->getMode();
		if($m=="create" || $m=="xcreate")
		{
			$cols=array_merge($cols,$this->_missingcols,$this->_missingattrs);
			$this->log("Newly created items will have default values for columns:".implode(",",array_merge($this->_missingcols,$this->_missingattrs)),"startup");
		}
	}
	
	
	public function initializeBaseCols(&$item)
	{
		foreach($this->_missingcols as $missing)
		{
			$item[$missing]=$this->_basecols[$missing];
		}
	}
	
	public function initializeBaseAttrs(&$item)
	{
		foreach($this->_missingattrs as $missing)
		{
			$item[$missing]=$this->_baseattrs[$missing];
		}
	}
	
	

	public function processItemAfterId(&$item,$params=null)
	{
		if($params["new"]==true)
		{
			$this->initializeBaseCols($item);
			$this->initializeBaseAttrs($item);
		}
		//forcing default values for mandatory processing columns 
		foreach($this->_forcedefault as $k=>$v)
		{
			if(isset($item[$k]) && trim($item[$k])=="")
			{
				$item[$k]=$v;
			}
		}
		return true;
	}
	
	/**
	 * attribute handler for decimal attributes
	 * @param int $pid	: product id
	 * @param int $ivalue : initial value of attribute
	 * @param array $attrdesc : attribute description
	 * @return mixed : false if no further processing is needed,
	 * 					string (magento value) for the decimal attribute otherwise
	 */
	public function handleDecimalAttribute($pid,&$item,$storeid,$attrcode,$attrdesc,$ivalue)
	{
		//force convert decimal separator to dot
		$ivalue=str_replace(",",".",$ivalue);
		$ovalue=deleteifempty($ivalue);
		return $ovalue;
	}

	/**
	 * attribute handler for datetime attributes
	 * @param int $pid	: product id
	 * @param int $ivalue : initial value of attribute
	 * @param array $attrdesc : attribute description
	 * @return mixed : false if no further processing is needed,
	 * 					string (magento value) for the datetime attribute otherwise
	 */
	public function handleDatetimeAttribute($pid,&$item,$storeid,$attrcode,$attrdesc,$ivalue)
	{
		$ovalue=deleteifempty(trim($ivalue));
		//Handle european date format or other common separators
		if(preg_match("|(\d{1,2})\D(\d{1,2})\D(\d{4})\s*(\d{2}:\d{2}:\d{2})?|",$ovalue,$matches))
		{
			$hms=count($matches)>4?$matches[4]:"";
			$ovalue=trim(sprintf("%4d-%2d-%2d %s",$matches[3],$matches[2],$matches[1],$hms));
		}
		return $ovalue;
	}

	public function handleTextAttribute($pid,&$item,$storeid,$attrcode,$attrdesc,$ivalue)
	{
		$ovalue=deleteifempty($ivalue);
		return $ovalue;	
	}
	
	public function checkInt($value)
	{
		return is_int($value) || (is_string($value) && is_numeric($value) && (int)$value==$value);
	}
	/**
	 * attribute handler for int typed attributes
	 * @param int $pid	: product id
	 * @param int $ivalue : initial value of attribute
	 * @param array $attrdesc : attribute description
	 * @return mixed : false if no further processing is needed,
	 * 					int (magento value) for the int attribute otherwise
	 */
	public function handleIntAttribute($pid,&$item,$storeid,$attrcode,$attrdesc,$ivalue)
	{
		$ovalue=$ivalue;
		$attid=$attrdesc["attribute_id"];
		//if we've got a select type value
		if($attrdesc["frontend_input"]=="select")
		{
			//we need to identify its type since some have no options
			switch($attrdesc["source_model"])
			{
				//if its status, default to 1 (Enabled) if not correcly mapped
				case "catalog/product_status":
					if(!$this->checkInt($ivalue) ){
						$ovalue=1;
					}
					break;
				//do not create options for boolean values tagged as select ,default to 0 if not correcly mapped
				case "eav/entity_attribute_source_boolean":
					if(!$this->checkInt($ivalue)){
						$ovalue=0;
					}
					break;
				//if visibility no options either,default to 4 if not correctly mapped
				case "catalog/product_visibility":
					if(!$this->checkInt($ivalue)){
						$ovalue=4;
					}
					
					break;
					//if it's tax_class, get tax class id from item value
				case "tax/class_source_product":
					$ovalue=$this->getTaxClassId($ivalue);
					break;
					//otherwise, standard option behavior
					//get option id for value, create it if does not already exist
					//do not insert if empty
				default:
					if($ivalue=="" && $this->currentItemExists())
					{
						return "__MAGMI_DELETE__";
					}
					$oids=$this->getOptionIds($attid,$storeid,array($ivalue));
					$ovalue=$oids[0];
					unset($oids);
					break;
			}
		}
		return $ovalue;
	}


	public function handleUrl_keyAttribute($pid,&$item,$storeid,$attrcode,$attrdesc,$ivalue)
	{
		
		$cpev=$this->tablename("catalog_product_entity_varchar");
		//find conflicting url keys
		$urlk=trim($ivalue);
		$exists=$this->currentItemExists();
		if($urlk=="" && $exists)
		{
			return "__MAGMI_DELETE__";
		}
		//for existing product, check if we have already a value matching the current pattern
		if($exists)
		{
			$sql="SELECT value FROM $cpev WHERE attribute_id=? AND entity_id=? AND value REGEXP ?";
			$eurl=$this->selectone($sql,array($attrdesc["attribute_id"],$pid,$urlk."(-\d+)?"),"value");
			//we match wanted pattern, try finding conflicts with our current one
			if($eurl)
			{
				$urlk=$eurl;
				$sql="SELECT * FROM $cpev WHERE attribute_id=? AND entity_id!=?  AND value=?";
				$umatch=$urlk;
			}
			//no current value, so try inserting into target pattern list
			else
			{
				
				$sql="SELECT * FROM $cpev WHERE attribute_id=? AND entity_id!=?  AND value REGEXP ?";
				$umatch=$urlk."(-\d+)?";
			}
		}
		$lst=$this->selectAll($sql,array($attrdesc["attribute_id"],$pid,$umatch));
		//all conflicting url keys
		if(count($lst)>0)
		{
			$urlk=$urlk."-".count($lst);
		}
		return $urlk;
	}
	/**
	 * attribute handler for varchar based attributes
	 * @param int $pid : product id
	 * @param string $ivalue : attribute value
	 * @param array $attrdesc : attribute description
	 */
	public function handleVarcharAttribute($pid,&$item,$storeid,$attrcode,$attrdesc,$ivalue)
	{

		$exists=$this->currentItemExists();
		if($storeid!==0 && empty($ivalue) && !$exists)
		{
			return false;
		}
		if($ivalue=="" && $exists)
		{
			return "__MAGMI_DELETE__";
		}
		
		$ovalue=$ivalue;
		$attid=$attrdesc["attribute_id"];
		//--- Contribution From mennos , optimized by dweeves ----
		//Added to support multiple select attributes
		//(as far as i could figure out) always stored as varchars
		//if it's a multiselect value
		if($attrdesc["frontend_input"]=="multiselect")
		{
			//if empty delete entry
			if($ivalue=="")
			{
				return "__MAGMI_DELETE__";
			}
			//magento uses "," as separator for different multiselect values
			$sep=Magmi_Config::getInstance()->get("GLOBAL","multiselect_sep",",");
			$multiselectvalues=explode($sep,$ivalue);
			$oids=$this->getOptionIds($attid,$storeid,$multiselectvalues);
			$ovalue=implode(",",$oids);
			unset($oids);
		}
	
		
		return $ovalue;
	}

}
