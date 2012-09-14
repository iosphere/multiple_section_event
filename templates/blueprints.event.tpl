<?php

	require_once(EXTENSIONS . '/multiple_section_event/events/event.multiple_section.php');

	Class event<!-- CLASS NAME --> extends MultipleSectionEvent {

		public $eParamROOTELEMENT = '%1$s';
		public $eParamSECTIONS = array('%2$s');
		public $eParamFILTERS = array('%3$s');

		public static function about(){
			return array(
				'name' => '<!-- NAME -->',
				'author' => array(
					'name' => '<!-- AUTHOR NAME -->',
					'website' => '<!-- AUTHOR WEBSITE -->',
					'email' => '<!-- AUTHOR EMAIL -->'),
				'version' => '<!-- VERSION -->',
				'release-date' => '<!-- RELEASE DATE -->'
			);
		}

		public static function allowEditorToParse(){
			return true;
		}

		public static function documentation() {
			return __('This is a Multiple Section Event. The automatic generation of the documentation will be implemented in a later version.');
		}

		public function load(){
			if(isset($_POST['action']['%1$s'])) {
				return $this->__trigger();
			}
		}

	}
