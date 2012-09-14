<?php

	require_once(TOOLKIT . '/class.event.php');
	require_once(TOOLKIT . '/events/class.event.section.php');
	require_once FACE . '/interface.event.php';

	Class MultipleSectionEvent extends SectionEvent implements iEvent {

		// This stores the section ID
		private static $sectionID = null;
	
		// Keep a record of all updated entries by section handle
		private $updated_entries = array();
		private $updated_counts  = array();
	
		// A record of created (deleteable) entries
		private $created_entries = array();
	
		// Second pass associations
		private $unresolved_links = array();
		private static $temporary_unresolved_links = null;
	
		// Store the redirect string
		private $redirect_url = null;
	
		public static function getName() {
			return __('Multiple Section Event');
		}

		public static function getClass() {
			return __CLASS__;
		}

		public static function getSource() {
			if (Symphony::Engine() instanceof Frontend) {
				return self::$sectionID;
			} else {
				return self::getClass();
			}
		}

		public static function getTemplate(){
			return EXTENSIONS . '/multiple_section_event/templates/blueprints.event.tpl';
		}

		public function settings() {
			$settings = array();

			$settings[self::getClass()]['rootelement'] = $this->eParamROOTELEMENT;
			$settings[self::getClass()]['sections'] = $this->eParamSECTIONS;

			return $settings;
		}

		public static function about() {
		}

	/*-------------------------------------------------------------------------
		Utilities
	-------------------------------------------------------------------------*/

		/**
		 * Returns the source value for display in the Events index
		 *
		 * @param string $file
		 *  The path to the Event file
		 * @return string
		 */
		public function getSourceColumn($handle) {
			$event = EventManager::create($handle, array(), false);

			if(isset($event->eParamSECTIONS) && !empty($event->eParamSECTIONS)) {
				$sections = array();
				foreach ($event->eParamSECTIONS as $id) {
					$section = SectionManager::fetch($id);

					if ($section) {
						$sections[] = Widget::Anchor(
							$section->get('name'),
							SYMPHONY_URL . '/blueprints/sections/edit/' . $section->get('id') . '/',
							$section->get('handle')
						)->generate();
					}
				}
				return implode($sections, ', ');
			}
			else {
				return 'Multiple Section Event';
			}
		}

		/**
		 * Check if an array has only numbered keys
		 *
		 * @param array $array
		 *  The array to check
		 * @return boolean
		 */
		public static function isArraySequential(array $array) {
			$concatenated_keys = '';

			foreach ($array as $key => $value) {
				$concatenated_keys .= $key;
			}

			return is_numeric($concatenated_keys);
		}

	/*-------------------------------------------------------------------------
		Editor
	-------------------------------------------------------------------------*/

		public static function buildEditor(XMLElement $wrapper, array &$errors = array(), array $settings = null, $handle = null) {
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings contextual ' . __CLASS__);
			$fieldset->appendChild(new XMLElement('legend', self::getName()));

			// Sections
			$label = Widget::Label(__('Sections'));

			$sections = SectionManager::fetch();
			$options = array();

			if(is_array($sections) && !empty($sections)) {
				if(!is_array($settings[self::getClass()]['sections'])) {
					$settings[self::getClass()]['sections'] = array();
				}
				foreach ($sections as $section) {
					$options[] = array(
						$section->get('id'), in_array($section->get('id'), $settings[self::getClass()]['sections']), $section->get('name')
					);
				}
			}

			$label->appendChild(Widget::Select('fields[' . self::getClass() . '][sections][]', $options, array('multiple' => 'multiple')));

			if(isset($errors[self::getClass()]['sections'])) $fieldset->appendChild(Widget::Error($label, $errors[self::getClass()]['sections']));
			else $fieldset->appendChild($label);

			$wrapper->appendChild($fieldset);
		}

		public static function validate(array &$settings, array &$errors) {
			if(empty($settings[self::getClass()]['sections'])) {
				$errors[self::getClass()]['sections'] = __('This is a required field');
			}

			return empty($errors[self::getClass()]);
		}

		public static function prepare(array $settings, array $params, $template) {
			if(!is_array($settings[self::getClass()]['sections'])) {
				$settings[self::getClass()]['sections'] = array();
			}
			if(!is_array($settings['filters'])) {
				$settings['filters'] = array();
			}
			
			return sprintf($template,
				$params['rootelement'],
				implode($settings[self::getClass()]['sections'], "', '"),
				implode($settings['filters'], "', '")
			);
		}

	/*-------------------------------------------------------------------------
		Execution
	-------------------------------------------------------------------------*/

		public function load(){
			if(isset($_POST['action'][$this->eParamROOTELEMENT])) {
				return $this->__trigger();
			}
		}

		public function execute() {
			if(!isset($this->eParamFILTERS) || !is_array($this->eParamFILTERS)){
				$this->eParamFILTERS = array();
			}

			$result = new XMLElement($this->eParamROOTELEMENT);

			// Check if event is admin only
			if(in_array('admin-only', $this->eParamFILTERS) && !Symphony::Engine()->isLoggedIn()){
				$result->setAttribute('result', 'error');
				$result->appendChild(new XMLElement('message', __('Entry encountered errors when saving.')));
				$result->appendChild($this->buildFilterElement('admin-only', 'failed'));
				return $result;
			}

			$post = General::getPostData();
			$success = true;

			// var_dump($post);die;

			// Process POST for each section separately
			require_once(TOOLKIT . '/class.sectionmanager.php');
			foreach ($this->eParamSECTIONS as $id) {
				$section = SectionManager::fetch($id);

				if ($section) {
					$entry_id = $position = $fields = null;
					self::$sectionID = $section->get('id');

					// Get POST data for this section
					if (self::isArraySequential($post[$section->get('handle')])) {
						foreach ($post[$section->get('handle')] as $position => $fields) {
							if (isset($fields['system:id']) && is_numeric($fields['system:id'])) {
								$entry_id = $fields['system:id'];
							}
							else {
								$entry_id = null;
							}

							$entry = new XMLElement('entry', null, array(
								'position' => $position,
								'index-key' => $position, // for EE and Form Controls compatibility
								'section-id' => $section->get('id'),
								'section-handle' => $section->get('handle')
							));

							$ret = $this->__doit($fields, $entry, $position, $entry_id);

							if (!$ret) {
								$success = false;
							}

							$result->appendChild($entry);
						}
					}
					else {
						$fields = $post[$section->get('handle')];
						$entry_id = null;

						if (isset($fields['system:id']) && is_numeric($fields['system:id'])) {
							$entry_id = $fields['system:id'];
						}

						$entry = new XMLElement('entry', null, array(
							'section-id' => $section->get('id'),
							'section-handle' => $section->get('handle')
						));

						$success = $this->__doit($fields, $entry, null, $entry_id);

						$result->appendChild($entry);
					}

				}
			}

			return $result;
		}

	}

	return 'MultipleSectionEvent';