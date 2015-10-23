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
	const B = 'Forms3rdPartyIntegration';

	/**
	 * Setting for how to include the attachments:  path (unchanged), url, raw/binary, base64
	 */
	const OPTION_ATTACH_HOW = 'f3if_how';
	/**
	 * Setting for what index to include the attachments
	 */
	const OPTION_ATTACH_AS = 'f3if_entry';

	const VAL_ATTACH_PATH = 'path';
	const VAL_ATTACH_LINK = 'url';
	const VAL_ATTACH_RAW = 'raw';
	const VAL_ATTACH_BAS64 = 'base64';

	/**
	 * Where the files should show up in the submission array for mapping
	 */
	const SUBMISSION_ATTACH = '_FILES_';

	static $_once = false;

	function __construct() {
		// expose files through submission $post array -- makes it available to mappings
		add_filter(self::B.'_get_submission', array(&$this, 'get_submission'), 11, 2);

		// if you don't want user to need to actually type in the mapping
		add_filter(self::B.'_service_filter_post', array(&$this, 'automap'), 11, 5);

		// configure whether to attach or not, how; because this is a base class only do it once
		if(!self::$_once) {
			add_filter(self::B.'_service_settings', array(&$this, 'service_settings'), 10, 3);
			self::$_once = true;
		}

		$this->_file_entry = self::SUBMISSION_ATTACH; // or get from a configurable wp_option?
	}

	private $_file_entry; // alias to where we stick it in the submission/post array

	public function get_submission($submission, $form) {
		return $submission + array($this->_file_entry=>$this->get_files()); 
	}

	abstract protected function get_files();

	public function automap($post, $service, $form, $sid, $submission) {
		// todo: apply shortcodes (or maybe just settings) to either:
		// 1. link to the file
		// 1-5. name of the file
		// 2. include the bytes
		// 3. base64 encode the bytes
		// 4. gzip the bytes
		// 6. shortcodes would allow combinations of the above
		
		// todo: filter result so others can add more stuff? probably unnecessary, can just attach later filter

		// not configured? ignore
		if(empty($service[self::OPTION_ATTACH_AS])) return $post;


		$post[$service[self::OPTION_ATTACH_AS]] = $submission[$this->_file_entry];

		return $post;
	}
	
	public static function init() {
		add_action(self::B.'_init', array(__CLASS__, 'register'), 11, 1);
	}
	
	// must add stuff after we're ready

	public static function register() {
		if(is_plugin_active('gravityforms/gravityforms.php') || class_exists('RGFormsModel') ) new F3i_GF_Files;
		if(is_plugin_active('contact-form-7/wp-contact-form-7.php') || class_exists('WPCF7_ContactForm') ) new F3i_CF7_Files;
		//if(is_plugin_active('ninja-forms/ninja-forms.php') || class_exists('Ninja_Forms') ) new F3i_Ninja_Files;
	}

	public function service_settings($eid, $P, $entity) {
	?>
		<fieldset><legend><span><?php _e('File Attachments', $P); ?></span></legend>
			<div class="inside">
				<em class="description">How to attach files to submission mappings.</em>

				<?php $field = self::OPTION_ATTACH_AS; ?>
				<div class="field">
					<label for="<?php echo $field, '-', $eid ?>"><?php _e('Map attachments as:', $P); ?></label>
					<input id="<?php echo $field, '-', $eid ?>" type="text" class="text" name="<?php echo $P, '[', $eid, '][', $field, ']'?>" value="<?php echo isset($entity[$field]) ? esc_attr($entity[$field]) : self::SUBMISSION_ATTACH ?>" />
					<em class="description"><?php _e('To what field name the file(s) will be mapped to in the post submission.  Leave blank to omit (and allow you to manually map it instead; note this will not apply transformations).', $P); ?></em>
				</div>
				<?php $field = self::OPTION_ATTACH_HOW; ?>
				<div class="field">
					<label for="<?php echo $field, '-', $eid ?>"><?php _e('Attachment style:', $P); ?></label>
					TODO: select box
					<input id="<?php echo $field, '-', $eid ?>" type="checkbox" class="checkbox" name="<?php echo $P, '[', $eid, '][', $field, ']'?>" value="yes"<?php echo isset($entity[$field]) ? ' checked="checked"' : ''?> />
					<em class="description"><?php _e('How to include file attachments.', $P); ?></em>
				</div>
			</div>
		</fieldset>
	<?php
	}

}
F3i_Files_Base::init();

#region ----------- activate plugins appropriately -----------

class F3i_GF_Files extends F3i_Files_Base {
	protected function get_files() {
		return $_FILES;
	}
}


class F3i_CF7_Files extends F3i_Files_Base {
	protected function get_files() {
		$cf7 = WPCF7_Submission::get_instance();
		return $cf7 ? $cf7->uploaded_files() : array();
	}
}

// not sure if this is necessary?
/*
class F3i_Ninja_Files extends F3i_Files_Base {
	protected function get_files() {
		return $_FILES;
	}
}
*/

#endregion ----------- activate plugins appropriately -----------
