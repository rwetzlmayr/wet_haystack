<?php
// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ('abc' is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'wet_haystack';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 0;

$plugin['version'] = '0.7';
$plugin['author'] = 'Robert Wetzlmayr';
$plugin['author_uri'] = 'https://wetzlmayr.at/awasteofwords/wet_haystack-textpattern-plugin';
$plugin['author_uri'] = 'https://wetzlmayr.at/awasteofwords/wet_haystack-textpattern-plugin';
$plugin['description'] = 'Custom full text index builder';
$plugin['type'] = 3;
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001);
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002);

$plugin['flags'] = PLUGIN_HAS_PREFS;

@include_once('zem_tpl.php');

if (0) {
?>
# --- BEGIN PLUGIN HELP ---

h3. Purpose

Textpattern's full text index uses the articles' body and title contents to find proper matches for site-internal searches.

*wet_haystack* allows site publishers to modify this default behaviour by adding additional article fields to the set of indexed content.

h3. Usage

NB: This plugin is intended for Textpattern 4.2.0+. It will not work with earlier versions. You need to acquire publisher privileges to change a site's fulltext search settings with this plugin.

h4. Building a custom fulltext index

* Go to the *wet_haystack options* panel.
* Check all the article fields you want to include in the full text index.
* Hit "Save" and wait for the database to re-index the content of your current site. Depending on the number and length of your articles, this may take a while.

You do not need to keep *wet_haystack* installed once the custom full-text index has been created.

h4. Removing an obsolete custom fulltext index

*wet_haystack* requires an additional database index for its operation, which it will continue to use as long as you change Textpattern's default full-text index settings. This custom full-text index will be deleted when the custom setting matches Textpattern's default setting (i.e. "Title" and "Body" are the only properties checked).

h3. Additional information

Visit the "wet_haystack documentation page":https://wetzlmayr.at/awasteofwords/wet_haystack-textpattern-plugin.

h3. Licence

This plugin is released under the "Gnu General Public Licence":https://www.gnu.org/licenses/gpl-3.0.txt.

# --- END PLUGIN HELP ---

<?php
}

# --- BEGIN PLUGIN CODE ---

global $textarray;

/**
 *  User-editable strings
 */
$textarray['wet_haystack'] = 'Fulltext Index';
$textarray['wet_haystack_select_columns'] = 'Select searchable columns';
$textarray['wet_haystack_please_wait'] = 'Please wait…';

// == STOP EDITING! ==

// Hook into the admin side of highly privileged users
add_privs('wet_haystack', '1');
add_privs('plugin_prefs.wet_haystack', '1');
register_callback('wet_haystack_ui', 'plugin_prefs.wet_haystack');
register_callback('wet_haystack_style', 'admin_side', 'head_end');
register_callback('wet_haystack_bhvr', 'admin_side', 'body_end');

/**
 * Admin side UI
 */
function wet_haystack_ui($event, $step)
{
  	global $prefs;

	// new fulltext index's key name
	$wet_haystack_key_name = 'wet_haystack';

  	// default TXP fulltext index
  	$default_cols = array('Title', 'Body');

  	$customfields = array();
  	$max_cf = get_pref('max_custom_fields', 10);
	for ($i = 1; $i <= $max_cf; $i++) {
  		if (!empty($prefs['custom_'.$i.'_set'])) {
			$customfields['custom_'.$i] = $prefs['custom_'.$i.'_set'];
		}
	}

  	// a map of db columns which may participate in a fulltext index, and their UI names.
  	$columns = array(
  		'AuthorID' 	=> 'login_name',
  		'Title' 	=> 'title',
  		'Body'		=> 'body',
  		'Excerpt'	=> 'excerpt',
  		'Keywords'	=> 'keywords',
  		'Category1'	=> 'category1',
  		'Category2'	=> 'category2',
  		'Section'	=> 'section'
  	) + $customfields;

  	// controller: build new fulltext index if requested
	$saved = false;
	if (gps('save')) {
  		$new_cols = gps('col');
  		if (!empty($new_cols)) {
  			$new_cols = array_keys($new_cols);
	  		$current_cols = wet_haystacked_cols($wet_haystack_key_name);
	  		if (!empty($current_cols)) safe_alter("textpattern", "DROP INDEX $wet_haystack_key_name");

	  		$doit = array_diff($new_cols, $default_cols) + array_diff($default_cols, $new_cols);
	  		if (!empty($doit)) {
	  			@set_time_limit(0);
	  			safe_alter("textpattern", "ADD FULLTEXT $wet_haystack_key_name (`" . join('`, `', doSlash($new_cols)) ."`)");
	  		}
  			update_lastmod();
	  		set_pref('searchable_article_fields', join(',', $new_cols), 'publish', 2);
	  		$saved = true;
  		}
  	}

  	// model: retrieve current fulltext index from database
  	$indexed_cols = wet_haystacked_cols($wet_haystack_key_name);
  	if (empty($indexed_cols)) $indexed_cols = $default_cols;

	// view: show current fulltext index, request new columns
  	pagetop(gTxt('wet_haystack'), ($saved ? gTxt('preferences_saved') : ''));

	foreach ($columns as $column => $label) {
		$checked = in_array($column, $indexed_cols);
		$check[] = graf(checkbox("col[$column]", 1, $checked, '', "col-$column")."<label for='col-$column'>".gTxt($label)."</label>");
	}

	echo "<div class='wet_modal'></div>".
		form(
		n.hed(gTxt('wet_haystack_select_columns'), 3).
		n.'<div id="wet_haystack_columns">'. // sorry, no fieldset. could not make overflow: auto work on fieldsets
		n.join(n, $check).
		n.'</div>'.
		n.eInput('plugin_prefs.wet_haystack').
		n.graf(
			fInput('submit', 'save', gTxt('save'), 'smallerbox', '', '', '', '', 'wet_haystack_save').
			href(gTxt('cancel'), '?event=plugin', ' id="wet_haystack_cancel"'),
			' id="wet_haystack_buttons"'),
        '', '', 'post', 'wet_modal'
	);
}

/**
 *  Return array of currently indexed column names
 */
function wet_haystacked_cols($key_name)
{
	$indexed_cols = array();
	$indexes = safe_show('INDEX', 'textpattern');
	foreach ($indexes as $index) {
		if(($index['Index_type'] == 'FULLTEXT') && ($index['Key_name'] == $key_name)) {
			$indexed_cols[] = $index['Column_name'];
		}
	}
	return $indexed_cols;
}

/**
 * Beautfying...
 */
function wet_haystack_style($event, $step)
{
	echo n.'<style>
div.wet_modal{background-color:black;opacity:0.2;position:absolute;top:0;left:0;width:100%;height:100%}
form.wet_modal{z-index:1000;width:20em;position:absolute;top:30px;left:50%;margin-left:-10em;background-color:white;padding:20px;border:2px solid #fc3;}
form.wet_modal h3{border-bottom: 1px solid #ddd;padding-bottom:2px}
#wet_haystack_save.busy{color:#999;}
#wet_haystack_cancel{margin-left: 1em;}
#wet_haystack_buttons{border-top: 1px solid #ddd;padding-top:5px;margin-top:5px;}
#wet_haystack_columns{border:none;padding:0;overflow:auto;max-height:300px;}
</style>
<!--[if gte IE 5]>
<style type="text/css">div.wet_modal{filter: alpha(opacity = 20);}</style>
<![endif]-->'
	.n;
}

function wet_haystack_bhvr($event, $step)
{
	$script = <<<EOS

<script type="text/javascript">
$(document).ready(function() {
	$("input#wet_haystack_save").click( function() {
		$(this).attr("value", "%wet_haystack_please_wait%");
		$(this).addClass("busy");
		return true;
	});
});
</script>

EOS;

	echo str_replace('%wet_haystack_please_wait%', gTxt('wet_haystack_please_wait'), $script);
}

# --- END PLUGIN CODE ---

?>
