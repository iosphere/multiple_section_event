<?php

	require_once(TOOLKIT . '/class.event.php');
	require_once(TOOLKIT . '/events/class.event.section.php');
	require_once FACE . '/interface.event.php';

	Class MultipleSectionEvent extends SectionEvent implements iEvent {

		// String matching
		const REGEX_PLACEHOLDER = '/(?<section>[a-z0-9-]+)(?:\[(?<position>\d+)\])?\[(?<field>([^\[:]+)(?::([^\[]*))*)\]/';

		// This stores the section ID
		private static $sectionID = null;

		// This stores the POSTed data
		private static $post = array();

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

		// Replace section[position][field] placeholders with their proper values
		private function __processPlaceholders($handle, $value, $position = null, $sectionID, $sectionHandle, &$local_unresolved_links) {
			// Reset the temporary container
			self::$temporary_unresolved_links = array();

			// If pcre.backtrack_limit is exceeded, preg_replace_callback returns NULL
			if ($new_value = preg_replace_callback(self::REGEX_PLACEHOLDER, array($this, 'placeholderCallback'), $value)) {
				if (isset($position)) {
					self::$post[$sectionHandle][$position][$handle] = $new_value;
				} else {
					self::$post[$sectionHandle][$handle] = $new_value;
				}
			}

			// var_dump(self::$temporary_unresolved_links);
			// Set up any unresolved links
			foreach (self::$temporary_unresolved_links as $link) {
				// var_dump($link);
				// Determine the target index
				// TODO Get proper target index
				$target_index = (count($this->updated_entries[$link['target-handle']]) > 1 ? $this->updated_counts[$sectionHandle] : 0);

				// Add to the section-level list of missed links
				$local_unresolved_links[] = array(
					'section-id' => $sectionID,
					'target-handle' => $link['target-handle'],
					// 'target-index' => $target_index,
					'target-index' => $link['target-index'] ? $link['target-index'] : $target_index,
					'field-name' => $link['field-name'],
					'replacement-key' => $link['replacement-key'],
					'this-postkey' => $sectionHandle,
					'this-key' => $handle
				);
				// var_dump($local_unresolved_links);
			}
		}

		// Callback for the replacement above
		public function placeholderCallback($matches) {
			// Only sections defined in the event can be referenced
			if (!in_array(SectionManager::fetchIDFromHandle($matches[1]), $this->eParamSECTIONS)) {
				return $matches[0];
			}

			// var_dump($matches);
			// system:id links
			if ($matches['field'] == 'system:id') {
				// There is a related system:id flying in the POST
				if (empty($matches['position']) && isset(self::$post[$matches['section']]['system:id'])) {
					return self::$post[$matches['section']]['system:id'];
				}
				else if (!empty($matches['position']) && isset(self::$post[$matches['section']][$matches['position']]['system:id'])) {
					return self::$post[$matches['section']][$matches['position']]['system:id'];
				}

				// Set the replacement key to dynamically get the system:id later
				if (empty($matches['position'])) {
					$replacement_key = '{@system-id:'.$matches['section'].'@}';
				} else {
					$replacement_key = '{@system-id:'.$matches['section'].':'.$matches['position'].'@}';
				}

				self::$temporary_unresolved_links[] =  array(
					'target-handle' => $matches['section'],
					'target-index' => $matches['position'],
					'field-name' => $matches['field'],
					'replacement-key' => $replacement_key
				);

				return $replacement_key;
			}

			// Other referenced fields
			if (empty($matches['position'])) {
				return self::$post[$matches['section']][$matches['field']];
			} else {
				return self::$post[$matches['section']][$matches['position']][$matches['field']];
			}
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

			// TODO Disable email and other filters
			// TODO Add filters at appropriate location

			self::$post = General::getPostData();
			$success = true;

			// var_dump(self::$post);

			require_once(TOOLKIT . '/class.sectionmanager.php');

			// Process each section separately
			foreach ($this->eParamSECTIONS as $id) {
				$section = SectionManager::fetch($id);

				if ($section) {
					$entry_id = $position = $fields = null;
					self::$sectionID = $section->get('id');

					// Create the list of updates
					if (!isset($this->updated_entries[$section->get('handle')])) {
						$this->updated_entries[$section->get('handle')] = array();
						$this->updated_counts[$section->get('handle')] = 0;
					}

					// Get POST data for this section
					if (self::isArraySequential(self::$post[$section->get('handle')])) {
						// TODO Merge with single entry POST
						foreach (self::$post[$section->get('handle')] as $position => $fields) {

							// Stage unresolvable links locally
							$local_unresolved_links = array();

							if (isset($fields['system:id']) && is_numeric($fields['system:id'])) {
								$entry_id = $fields['system:id'];
							}
							else {
								$entry_id = null;
							}

							$entry = new XMLElement('entry', null, array(
								'position' => $position,
								'index-key' => $position, // for EE / Form Controls compatibility
								'section-id' => $section->get('id'),
								'section-handle' => $section->get('handle')
							));

							foreach ($fields as $handle => $value) {
								$this->__processPlaceholders($handle, $value, $position, $section->get('id'), $section->get('handle'), $local_unresolved_links);
							}

							// Update $fields with new values
							// TODO I guess this is not the right way to do it
							$fields = self::$post[$section->get('handle')][$position];

							$ret = $this->__doit($fields, $entry, $position, $entry_id);

							if (!$ret) {
								$success = false;
							}

							// Maintain the updates list
							$this->updated_entries[$section->get('handle')][$position] = $entry->getAttribute("id");
							$this->updated_counts[$section->get('handle')]++;

							// Maintain the unresolved links list
							foreach ($local_unresolved_links as $link) {
								$link['entry-id'] = $entry->getAttribute('id');
								$this->unresolved_links[] = $link;
							}

							$result->appendChild($entry);
						}
					}
					else {
						// TODO Merge with multiple entry POST

						// Stage unresolvable links locally
						$local_unresolved_links = array();

						$fields = self::$post[$section->get('handle')];
						$entry_id = null;

						if (isset($fields['system:id']) && is_numeric($fields['system:id'])) {
							$entry_id = $fields['system:id'];
						}

						$entry = new XMLElement('entry', null, array(
							'section-id' => $section->get('id'),
							'section-handle' => $section->get('handle')
						));

						foreach ($fields as $handle => $value) {
							$this->__processPlaceholders($handle, $value, null, $section->get('id'), $section->get('handle'), $local_unresolved_links);
						}

						// Update $fields with new values
						// TODO I guess this is not the right way to do it
						$fields = self::$post[$section->get('handle')];

						$success = $this->__doit($fields, $entry, null, $entry_id);

						// Maintain the updates list
						$this->updated_entries[$section->get('handle')][] = $entry->getAttribute("id");
						$this->updated_counts[$section->get('handle')]++;

						// Maintain the unresolved links list
						foreach ($local_unresolved_links as $link) {
							$link['entry-id'] = $entry->getAttribute('id');
							$this->unresolved_links[] = $link;
						}

						$result->appendChild($entry);
					}

				}
			}

			// var_dump(self::$post);

			// var_dump($this->unresolved_links);
			// var_dump($this->updated_entries);

			// Fix up the unresolved links
			$this->resolveLinks();

			return $result;
		}

		public function resolveLinks() {
			// Don't bother if we're rolling back
			// TODO implement rollback
			if ($this->rollback) return;

			foreach ($this->unresolved_links as $link) {
				// var_dump($link);
				// var_dump(self::$post);
				// Fake the getSource() method
				self::$sectionID = $link['section-id'];

				$fields = array();

				// Set up the ID
				$fields['id'] = $link['entry-id'];

				// var_dump($this->updated_entries);
				// And pop in the field data
				$value = $this->updated_entries[$link['target-handle']][$link['target-index']];
				// var_dump($value);

				if ($link['replacement-key']) {
					// Replace the provided key with the system id
					if ($link['target-index'] != '0') {
						$fields[$link['this-key']] = preg_replace('/' . $link['replacement-key'] . '/', $value, self::$post[$link['this-postkey']][$link['target-index']][$link['this-key']]);
					}
					else {
						$fields[$link['this-key']] = preg_replace('/' . $link['replacement-key'] . '/', $value, self::$post[$link['this-postkey']][$link['this-key']]);
					}
				}
				else {
					$fields[$link['field-name']] = $value;
				}
				// var_dump($fields);

				// Set up dummy 
				$result = new XMLElement('temp-' . $link['this-postkey']);

				if ($value) {
					$this->__doit($fields, $result, null, $link['entry-id']);
				}
			}
		}

	}

	return 'MultipleSectionEvent';