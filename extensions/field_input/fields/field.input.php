<?php
	
	require_once(TOOLKIT . '/class.xslproc.php');
	
	Class fieldInput extends Field {
		public function __construct(&$parent){
			parent::__construct($parent);
			$this->_name = __('Text Input');
			$this->_required = true;
			
			$this->set('required', 'no');
		}

		public function allowDatasourceOutputGrouping(){
			return true;
		}
		
		public function allowDatasourceParamOutput(){
			return true;
		}

		public function groupRecords($records){
			
			if(!is_array($records) || empty($records)) return;
			
			$groups = array($this->get('element_name') => array());
			
			foreach($records as $r){
				$data = $r->getData($this->get('id'));
				
				$value = $data['value'];
				$handle = Lang::createHandle($value);
				
				if(!isset($groups[$this->get('element_name')][$handle])){
					$groups[$this->get('element_name')][$handle] = array('attr' => array('handle' => $handle, 'value' => $value),
																		 'records' => array(), 'groups' => array());
				}	
																					
				$groups[$this->get('element_name')][$handle]['records'][] = $r;
								
			}

			return $groups;
		}	
		
		public function displayDatasourceFilterPanel(XMLElement &$wrapper, $data=NULL, MessageStack $errors=NULL){ //, $fieldnamePrefix=NULL, $fieldnamePostfix=NULL){

			$wrapper->appendChild(new XMLElement('h4', $this->get('label') . ' <i>' . $this->Name() . '</i>'));
			
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Operation'));
			$label->appendChild(Widget::Select(
				'fields[filter]'
				//. (!is_null($fieldnamePrefix) ? "[{$fieldnamePrefix}]" : NULL)
				. '[' . $this->get('element_name') . '][operation]',
				//. (!is_null($fieldnamePostfix) ? "[{$fieldnamePostfix}]" : NULL), 
				//(!is_null($data) ? General::sanitize($data) : NULL)
				array(
					array('is', false, 'Is'),
					array('is-not', ($data['operation'] == 'is-not'), 'Is Not'),
					array('contains', ($data['operation'] == 'contains'), 'Contains'),
					array('matches-expression', ($data['operation'] == 'matches-expression'), 'Matches Expression'),
				)
			));
			$group->appendChild($label);
			
			$label = Widget::Label(__('Value'));
			$label->appendChild(Widget::Input(
				'fields[filter]'
				//. (!is_null($fieldnamePrefix) ? "[{$fieldnamePrefix}]" : NULL)
				. '[' . $this->get('element_name') . '][value]',
				//. (!is_null($fieldnamePostfix) ? "[{$fieldnamePostfix}]" : NULL), 
				(!is_null($data) && isset($data['value']) ? General::sanitize($data['value']) : NULL)
			));
			$group->appendChild($label);
			
			$wrapper->appendChild($group);	
		}

		public function displayPublishPanel(&$wrapper, $data=NULL, $flagWithError=NULL, $fieldnamePrefix=NULL, $fieldnamePostfix=NULL){
			$value = General::sanitize($data['value']);
			$label = Widget::Label($this->get('label'));
			if($this->get('required') != 'yes') $label->appendChild(new XMLElement('i', __('Optional')));
			$label->appendChild(Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, (strlen($value) != 0 ? $value : NULL)));

			if($flagWithError != NULL) $wrapper->appendChild(Widget::wrapFormElementWithError($label, $flagWithError));
			else $wrapper->appendChild($label);
		}
		
		public function isSortable(){
			return true;
		}
		
		public function canFilter(){
			return true;
		}
		
		public function canImport(){
			return true;
		}

		public function buildSortingSQL(&$joins, &$where, &$sort, $order='ASC'){
			$joins .= "LEFT OUTER JOIN `tbl_entries_data_".$this->get('id')."` AS `ed` ON (`e`.`id` = `ed`.`entry_id`) ";
			$sort = 'ORDER BY ' . (in_array(strtolower($order), array('random', 'rand')) ? 'RAND()' : "`ed`.`value` $order");
		}
		
		public function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation = false) {
			$field_id = $this->get('id');
			
			if (self::isFilterRegex($data[0])) {
				$this->_key++;
				$pattern = str_replace('regexp:', '', $this->cleanValue($data[0]));
				$joins .= "
					LEFT JOIN
						`tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
						ON (e.id = t{$field_id}_{$this->_key}.entry_id)
				";
				$where .= "
					AND (
						t{$field_id}_{$this->_key}.value REGEXP '{$pattern}'
						OR t{$field_id}_{$this->_key}.handle REGEXP '{$pattern}'
					)
				";
				
			} elseif ($andOperation) {
				foreach ($data as $value) {
					$this->_key++;
					$value = $this->cleanValue($value);
					$joins .= "
						LEFT JOIN
							`tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
							ON (e.id = t{$field_id}_{$this->_key}.entry_id)
					";
					$where .= "
						AND (
							t{$field_id}_{$this->_key}.value = '{$value}'
							OR t{$field_id}_{$this->_key}.handle = '{$value}'
						)
					";
				}
				
			} else {
				if (!is_array($data)) $data = array($data);
				
				foreach ($data as &$value) {
					$value = $this->cleanValue($value);
				}
				
				$this->_key++;
				$data = implode("', '", $data);
				$joins .= "
					LEFT JOIN
						`tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
						ON (e.id = t{$field_id}_{$this->_key}.entry_id)
				";
				$where .= "
					AND (
						t{$field_id}_{$this->_key}.value IN ('{$data}')
						OR t{$field_id}_{$this->_key}.handle IN ('{$data}')
					)
				";
			}
			
			return true;
		}

		private function __applyValidationRules($data){			
			$rule = $this->get('validator');
			return ($rule ? General::validateString($data, $rule) : true);
		}
		
		private function __replaceAmpersands($value) {
			return preg_replace('/&(?!(#[0-9]+|#x[0-9a-f]+|amp|lt|gt);)/i', '&amp;', trim($value));
		}

		public function checkPostFieldData($data, &$message, $entry_id=NULL){

			$message = NULL;
			
			$handle = Lang::createHandle($data);
			
			if($this->get('required') == 'yes' && strlen($data) == 0){
				$message = __("'%s' is a required field.", array($this->get('label')));
				return self::__MISSING_FIELDS__;
			}	
			
			if(!$this->__applyValidationRules($data)){
				$message = __("'%s' contains invalid data. Please check the contents.", array($this->get('label')));
				return self::__INVALID_FIELDS__;	
			}

			return self::__OK__;
							
		}
		
		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = null) {

			$status = self::__OK__;
			
			if (strlen(trim($data)) == 0) return array();
			
			$result = array(
				'value' => $data
			);
			
			$result['handle'] = Lang::createHandle($result['value']);
			
			return $result;
		}

		public function canPrePopulate(){
			return true;
		}

		public function appendFormattedElement(&$wrapper, $data, $encode=false){

			$value = $data['value'];

			if($encode === true){
				$value = General::sanitize($value);
			}
			
			else{
				include_once(TOOLKIT . '/class.xslproc.php');

				if(!General::validateXML($data['value'], $errors)){
					$value = html_entity_decode($data['value'], ENT_QUOTES, 'UTF-8');
					$value = $this->__replaceAmpersands($value);

					if(!General::validateXML($value, $errors)){
						$value = General::sanitize($data['value']);
					}
				}
			}
			
			$wrapper->appendChild(
				new XMLElement(
					$this->get('element_name'), $value, array('handle' => $data['handle'])
				)
			);
		}
		
		public function commit(){

			if(!parent::commit()) return false;
			
			$id = $this->get('id');

			if($id === false) return false;
			
			$fields = array();
			
			$fields['field_id'] = $id;
			$fields['validator'] = ($fields['validator'] == 'custom' ? NULL : $this->get('validator'));
			
			Symphony::Database()->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '$id' LIMIT 1");
				
			return Symphony::Database()->insert($fields, 'tbl_fields_' . $this->handle());
					
		}
		
		public function setFromPOST($postdata){		
			parent::setFromPOST($postdata);			
			if($this->get('validator') == '') $this->remove('validator');				
		}
		
		public function displaySettingsPanel(&$wrapper, $errors = null) {
			parent::displaySettingsPanel($wrapper, $errors);
			
			$this->buildValidationSelect($wrapper, $this->get('validator'), 'validator');
			
			$options_list = new XMLElement('ul');
			$options_list->setAttribute('class', 'options-list');
			
			$this->appendRequiredCheckbox($options_list);
			$this->appendShowColumnCheckbox($options_list);
			
			$wrapper->appendChild($options_list);
						
		}
		
		public function createTable(){
			
			return Symphony::Database()->query(
			
				"CREATE TABLE IF NOT EXISTS `tbl_entries_data_" . $this->get('id') . "` (
				  `id` int(11) unsigned NOT NULL auto_increment,
				  `entry_id` int(11) unsigned NOT NULL,
				  `handle` varchar(255) default NULL,
				  `value` varchar(255) default NULL,
				  PRIMARY KEY  (`id`),
				  KEY `entry_id` (`entry_id`),
				  KEY `handle` (`handle`),
				  KEY `value` (`value`)
				) TYPE=MyISAM;"
			
			);
		}		

	}

