<?php
/*

Plugin Name: Forms: 3rd-Party File Attachments
Plugin URI: https://github.com/zaus/forms-3rdparty-files
Description: Add file upload processing to Forms 3rdparty Integration
Author: zaus, dominiceales
Version: 0.1
Author URI: http://drzaus.com
Changelog:
	0.1 - initial idea from https://github.com/zaus/forms-3rdparty-integration/issues/40

*/


abstract class F3i_Files_Base {
	function __construct() {
		// expose files through submission $post array -- makes it available to mappings
		add_filter(Forms3rdPartyIntegration::$instance->N('get_submission'), array(&$this, 'get_submission'), 11, 2);

		// if you don't want user to need to actually type in the mapping
		add_filter(Forms3rdPartyIntegration::$instance->N('service_filter_post'), array(&$this, 'automap'), 11, 5);

		$this->_file_entry = 'FILES'; // or get from a configurable wp_option?
	}

	private $_file_entry; // alias to where we stick it in the submission/post array

	public function get_submission($submission, $form) {
		return $submission + array($this->_file_entry=>$this->get_files()); 
	}

	abstract protected function get_files();

	public function automap($post, $service, $form, $sid, $submission) {
		$post[$this->_file_entry] = $submission[$this->_file_entry];
	}
}

/** plugin -- active if using GF **/
public class F3i_GF_Files : F3i_Files_Base {
	protected function get_files() {
		return $_FILES;
	}
}


/** plugin -- active if using CF7 **/
public class F3i_CF7_Files : F3i_Files_Base {
	protected function get_files() {
		$cf7 = WPCF7_Submission::get_instance();
		return $cf7 ? $cf7->uploaded_files() : array();
	}
}