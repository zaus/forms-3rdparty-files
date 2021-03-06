=== Forms: 3rd-Party File Attachments ===
Contributors: zaus, dominiceales
Donate link: http://drzaus.com/donate
Tags: contact form, form, contact form 7, CF7, gravity forms, GF, CRM, mapping, 3rd-party service, services, remote request, file attachment, upload, file upload
Requires at least: 3.0
Tested up to: 4.9.6
Stable tag: trunk
License: GPLv2 or later

Add file upload processing to Forms 3rdparty Integration.

== Description ==

Exposes file upload/attachments to the regular service mapping of [Forms 3rdparty Integration](http://wordpress.org/plugins/forms-3rdparty-integration/).

From discussion at https://github.com/zaus/forms-3rdparty-integration/issues/40.

== Installation ==

1. Unzip, upload plugin folder to your plugins directory (`/wp-content/plugins/`)
2. Make sure [Forms 3rdparty Integration](http://wordpress.org/plugins/forms-3rdparty-integration/) is installed and settings have been saved at least once.
3. Activate plugin
4. Choose how the files will be attached -- either:
	* as server path
	* as url
	* as base64-encoded bytes
	* as raw contents
5. Map to the desired file detail, where _"[field]"_ is the corresponding input field name as you would normally map:
	* `[field]` -- the filename
	* `[field]_attach` -- the transformed attachment from the previous step
	* `[field]_mime` -- the file's actual mime-type
	* `[field]_size` -- the file size


== Frequently Asked Questions ==

= How do I perform the appropriate transforms in custom hooks =

Using `F3i_Files_Base::Transform($value, $how)` where `$how` is:
* `path`
* `url`
* `base64`
* `raw`

= Gravity Forms input ids cause conflicts =

Use the 'label' option to instead reference file uploads via the field label.  You can also set the Advanced > Admin Field Label to use a shorter/non-display label than what users see on the form.

ex) If your upload field `input_5` has a very long label "Choose one or more files blah blah blah" you would reference details like `Choose one or more files blah blah blah_attach` unless you set an admin label like "FileUpload", in which case you'd map to `FileUpload_attach`.  You can also use this to attach to different upload fields across forms -- normally they might not be able to share the input_id, but if you give them the same admin field label then you only need to map the service once to that admin label.

= This only works for GF or CF7, what about Ninja Forms or some other form plugin? =

Message the author about adding it, or:
1. extend `F3i_Files_Base` and declare a method `get_files` that returns an array of (input_field => filepath)
2. hook to `F3i_Files_Base_register` and declare a new instance of your class

_(A note about Ninja Forms -- file uploads are a paid addon, and the author doesn't have a copy, so adding it wasn't on the roadmap)_

= It doesn't work right... =

Drop an issue at https://github.com/zaus/forms-3rdparty-files

== Screenshots ==

N/A.

== Changelog ==

= 0.5.2 =
* added special GF option to use field labels
* some under-the-hood refactoring for clarity, reuse

= 0.5 =
* refactored support for GF single and multifile fields
* fix: collapsing ui

= 0.4.1 =
* fix #2 -- GF validation errors removes filename, fallback to path basename

= 0.4 =
* including `$form` in `_get_files` hook
* consolidating byte handling between 'raw' and 'base64'
* no longer throws an exception if unable to get file, instead returns an error array
* fixed for GF 2.0.7.2 temp path issue #1
* new filter: `_get_path` used for GF bug

= 0.3 =
* refactored inheritance, 'better' form registration, include ninja forms

= 0.2 =
* added "meta" details
* breaking change - removed overwrite setting as unnecessary (due to compatible formatting)
* works with GF and CF7

= 0.1 =

IT HAS BEGUN

== Upgrade Notice ==

= 0.4 =
* breaking change for GF due to temp path handling, see [github #1](https://github.com/zaus/forms-3rdparty-files/issues/1)

= 0.3 =
* changed base plugin class name and inheritance, removed registration hook

= 0.2 =
* 'overwrite' setting no longer available; map name with the input field name and file attachment with _theinputfieldname_attach_