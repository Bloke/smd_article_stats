<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_article_stats';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.30';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'http://stefdawson.com/';
$plugin['description'] = 'Get article/excerpt statistics and display them to visitors';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '1';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@smd_artstat
smd_artstat => Article statistics
smd_artstat_fields => Word count fields and DOM selectors
smd_artstat_id => Show article ID
smd_artstat_legend => Article stats
smd_artstat_pos => Position of stats panel
smd_artstat_pos_above_status => Above Status
smd_artstat_pos_above_textfilter => Above Textfilter
smd_artstat_pos_above_textile => Above Textile
smd_artstat_pos_above_title => Above Title
smd_artstat_pos_below_author => Below Author
smd_artstat_pos_below_excerpt => Below Excerpt
smd_artstat_pos_below_textfilter => Below Textfilter
smd_artstat_pos_below_textile => Below Textile
smd_artstat_set_by_admin => Set by administrator
smd_artstat_singular => Numbers treated as 'singular'
smd_artstat_word_plural => words
smd_artstat_word_singular => word
#@smd_artstat
#@language fr-fr
smd_artstat => Statistiques d'article
smd_artstat_fields => Champs de décompte et sélecteur du DOM
smd_artstat_id => Afficher l'ID de l'article
smd_artstat_legend => Statistiques article
smd_artstat_pos => Emplacement dans la page
smd_artstat_pos_above_status => Au dessus des statuts
smd_artstat_pos_above_textfilter => Au dessus du sélecteur Aide Textile
smd_artstat_pos_above_textile => Au dessus de l'aide Textile
smd_artstat_pos_above_title => Au dessus du titre
smd_artstat_pos_below_author => Sous le nom d'auteur
smd_artstat_pos_below_excerpt => Sous le résumé
smd_artstat_pos_below_textfilter => En dessous du sélecteur Aide Textile
smd_artstat_pos_below_textile => En dessous de l'aide Textile
smd_artstat_singular => Nombre pris au singulier
smd_artstat_word_plural => Mots
smd_artstat_word_singular => Mot
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_article_stats
 *
 * A Textpattern CMS plugin for counting words in article fields and optionally displaying them to visitors
 *  -> Choose which fields to count on the admin side
 *  -> Customize where you want the count to be displayed
 *  -> Shows ID of currently edited article.
 *
 * @author Stef Dawson
 * @link   http://stefdawson.com/
 */

// TODO:
//  * TinyMCE -- accessing fields inside iframes?

if (@txpinterface == 'admin') {
	$all_privs = array_keys(get_groups());
	unset($all_privs[array_search('0', $all_privs)]); // Remove 'none'
	$all_joined = join(',', $all_privs);

	add_privs('smd_artstat_prefs', $all_joined);
	add_privs('prefs.smd_artstat', $all_joined);
	register_callback('smd_artstat_prefs', 'prefs', '', 1);
	register_callback('smd_article_info', 'article');
	$smd_ai_prefs = smd_article_info_prefs();
	foreach ($smd_ai_prefs as $key => $prefobj) {
		register_callback('smd_article_info_pophelp', 'admin_help', $key);
	}
}

// Public side info
function smd_article_stats($atts, $thing=NULL) {
	global $thisarticle;

	assert_article();

	extract(lAtts(array(
		'wraptag'  => '',
		'class'    => __FUNCTION__,
		'break'    => '',
		'label'    => '',
		'labeltag' => '',
		'item'     => '',
	), $atts));

	$out = array();

	// item not specified? Use the array 'keys' from the pref
	if (empty($item)) {
		$fldList = do_list(get_pref('smd_artstat_fields'));
		$cfs = getCustomFields();

		foreach ($fldList as $fld) {
			$fldInfo = do_list($fld, '->');
			$field = $fldInfo[0];
			if (strpos($field, 'custom_') !== false) {
				$cfnum = str_replace('custom_', '', $field);
				if (array_key_exists($cfnum, $cfs)) {
					$field = $cfs[$cfnum];
				} else {
					// Bogus CF: skip it
					continue;
				}
			}
			$item[] = strtolower($field);
		}
	} else {
		$item = do_list($item);
	}

	$out[] = smd_article_info_count($item, $thisarticle);

	return doLabel($label, $labeltag).doWrap($out, $wraptag, $break, $class);
}

// Admin-side info -- auto-updated via jQuery
function smd_article_info($event, $step) {
	global $app_mode;

	extract(gpsa(array('view')));

	include_once txpath.'/publish/taghandlers.php';
	if(!$view || gps('save') || gps('publish')) {
		$view = 'text';
	}

	if ($view == 'text') {
		$screen_locs = array(
			'none'                  => '',
			'excerpt_below'         => 'jq|.excerpt|after',
			'author_below'          => 'jq|.author|after',
			'status_above'          => 'jq|#write-status|before',
			'title_above'           => 'jq|#article-main|prepend',
			'textfilter_help_above' => 'jq|#textfilter_group|before',
			'textfilter_help_below' => 'jq|#textfilter_group|after', // For 4.6.x+
			'textile_help_above'    => 'jq|#article-col-1|prepend',
			'textile_help_below'    => 'jq|#textile_help|after', // For 4.5.x
		);

		// Check hidden pref and sanitize
		$posn = get_pref('smd_artstat_pos', 'status_above');
		$posn = (array_key_exists($posn, $screen_locs)) ? $posn : 'status_above';

		$placer = explode('|', $screen_locs[$posn]);
		doArray($placer, 'escape_js');

		// Split and recombine to get rid of spaces
		// TODO: error detection if missing entries
		$fldList = do_list(get_pref('smd_artstat_fields', 'Body -> #body, Excerpt -> #excerpt'));
		$fldAnchors = array('0'); // Placeholder since Status isn't a countable field, but we need it later
		$db_fields = array('Status');

		foreach ($fldList as $fld) {
			$fldInfo = do_list($fld, '->');
			$db_fields[] = $fldInfo[0];
			if (isset($fldInfo[1])) {
				$fldAnchors[] = $fldInfo[1];
			}
		}

		array_shift($fldAnchors); // Goodbye Status anchor
		$js_fields = escape_js(implode(',', $fldAnchors));
		$js_array_fields = implode(',', doArray(doArray($fldAnchors, 'escape_js'), 'doQuote'));

		$id = (empty($GLOBALS['ID']) ? gps('ID') : $GLOBALS['ID']);

		if (empty($id)) {
			$rs = $db_fields;
		} else {
			$rs = safe_row(join(',', doArray($db_fields, 'doSlash')), 'textpattern', 'ID='.doSlash($id));
		}

		$idlink = (get_pref('smd_artstat_id') === '1') ? (($id && in_array($rs['Status'], array(STATUS_LIVE, STATUS_STICKY))) ? href($id, permlinkurl_id($id)) : $id) : '';
		$indiv = array();
		$words = 0;

		array_shift($db_fields); // Goodbye Status field
		foreach ($db_fields as $idx => $fld) {
			$wc = smd_article_info_count($fld, $rs);
			$words += $wc;
			$indiv[] = '<span class="smd_article_stats_' . $idx . '">'.$wc.'</span>';
		}
		gTxtScript(array('smd_artstat_word_singular', 'smd_artstat_word_plural'));
		$singstring = get_pref('smd_artstat_singular', '1');
		$singles = do_list($singstring);
		$out1 = escape_js(
			defined('PREF_PLUGIN')
				? wrapGroup('smd_artstat', '<span class="smd_article_stats_wc">'.$words.'</span> <span class="smd_article_stats_wd">'.(in_array($words, $singles) ? gTxt('smd_artstat_word_singular') : gTxt('smd_artstat_word_plural')).'</span>: ( ' . join(' / ', $indiv) .' )'.(($idlink) ? ' | ' . gTxt('id') .n. $idlink : ''), 'smd_artstat')
				: '<fieldset><legend>'.gTxt('smd_artstat_legend').'</legend><p><span class="smd_article_stats_wc">'.$words.'</span> <span class="smd_article_stats_wd">'.(in_array($words, $singles) ? gTxt('smd_artstat_word_singular') : gTxt('smd_artstat_word_plural')).'</span>: ( ' . join(' / ', $indiv) .' )'.(($idlink) ? ' | ' . gTxt('id') .n. $idlink : '').'</p></fieldset>'
		);
		$out2 = script_js(<<<EOJS
jQuery(function() {
	var singlist = [{$singstring}];

	jQuery("{$js_fields}").keyup(function() {
		var flds = [{$js_array_fields}];
		var wds = 0;
		var content = '';
		for (idx = 0; idx < flds.length; idx++) {
			if (jQuery(flds[idx]).length > 0) {
				content = jQuery(flds[idx]).val();
				content += (content.length > 0) ? " " : "";
				word_count = content.replace(/(<([^>]+)>)/ig,"").split(/\s+/).length-1
				jQuery(".smd_article_stats_"+idx).text(word_count);
				wds += word_count;
			}
		}

		jQuery(".smd_article_stats_wc").text(wds);
		jQuery(".smd_article_stats_wd").text(((jQuery.inArray(wds, singlist) > -1) ? textpattern.gTxt('smd_artstat_word_singular') : textpattern.gTxt('smd_artstat_word_plural')));
	}).keyup();
});
EOJS
		);

		if ($placer[0] == 'jq' && $app_mode != 'async') {
			echo '<script type="text/javascript">jQuery(function() { jQuery("'.$placer[1].'").'.$placer[2].'(\''.$out1.'\'); });</script>'.$out2;
		}
	}
}

// Get pophelp content from stefdawson.com
function smd_article_info_pophelp($evt, $stp, $ui, $vars) {
	return str_replace(HELP_URL, 'http://stefdawson.com/downloads/support/', $ui);
}

// Install prefs if they don't already exist
function smd_artstat_prefs($evt, $stp) {
	$smd_ai_prefs = smd_article_info_prefs();
	foreach ($smd_ai_prefs as $key => $prefobj) {
		if (get_pref($key) === '') {
			set_pref($key, doSlash($prefobj['default']), 'smd_artstat', $prefobj['type'], $prefobj['html'], $prefobj['position'], $prefobj['visibility']);
		}
	}
}

// Only render the pref if enough privs exist
function smd_artstat_restricted($key, $val) {
	global $txp_user;
	static $smd_artstat_privs = array();

	if (array_key_exists($txp_user, $smd_artstat_privs)) {
		$privs = $smd_artstat_privs[$txp_user];
	} else {
		$safe_user = doSlash($txp_user);
		$privs = safe_field('privs', 'txp_users', "name='$safe_user'");
		$smd_artstat_privs[$txp_user] = $privs;
	}
   if ($privs === '1') {
		return fInput('text', $key, $val, '', '', '', INPUT_REGULAR);
	} else {
		return gTxt('smd_artstat_set_by_admin');
	}
}
// Render the position pref
function smd_artstat_pos($key, $val) {
	$smd_ai_prefs = smd_article_info_prefs();
	$obj = $smd_ai_prefs[$key];
	return selectInput($key, $obj['content'], $val);
}
// Settings for the plugin
// TODO: Use PREF_PLUGIN constant after 4.6.0 released
function smd_article_info_prefs() {
	$smd_ai_prefs = array(
		'smd_artstat_fields' => array(
			'html'       => 'smd_artstat_restricted',
			'type'       => PREF_ADVANCED,
			'position'   => 10,
			'default'    => 'Body -> #body, Excerpt -> #excerpt',
			'group'      => 'smd_artstat_settings',
			'visibility' => PREF_GLOBAL,
		),
		'smd_artstat_pos' => array(
			'html'       => 'smd_artstat_pos',
			'type'       => PREF_ADVANCED,
			'position'   => 20,
			'content'    => array(
				'none'                  => gTxt('none'),
				'excerpt_below'         => gTxt('smd_artstat_pos_below_excerpt'),
				'author_below'          => gTxt('smd_artstat_pos_below_author'),
				'status_above'          => gTxt('smd_artstat_pos_above_status'),
				'title_above'           => gTxt('smd_artstat_pos_above_title'),
				'textile_help_above'    => gTxt('smd_artstat_pos_above_textile'),
//				'textfilter_help_above' => gTxt('smd_artstat_pos_above_textfilter'), // Uncomment after 4.6.0 release and remove textile_help_above. Upgrade anyone using this setting to the new one automatically
				'textile_help_below'    => gTxt('smd_artstat_pos_below_textile'),
//				'textfilter_help_below' => gTxt('smd_artstat_pos_below_textfilter'), // Uncomment after 4.6.0 release and remove textile_help_below. Upgrade anyone using this setting to the new one automatically
				),
			'default'    => 'status_above',
			'group'      => 'smd_artstat_settings',
			'visibility' => PREF_PRIVATE,
		),
		'smd_artstat_id' => array(
			'html'       => 'yesnoradio',
			'type'       => PREF_ADVANCED,
			'position'   => 30,
			'default'    => '0',
			'group'      => 'smd_artstat_settings',
			'visibility' => PREF_PRIVATE,
		),
		'smd_artstat_singular' => array(
			'html'       => 'smd_artstat_restricted',
			'type'       => PREF_ADVANCED,
			'position'   => 40,
			'default'    => '1',
			'group'      => 'smd_artstat_settings',
			'visibility' => PREF_GLOBAL,
		),
	);

	return $smd_ai_prefs;
}

// Library function to count words in the given field items
function smd_article_info_count($item, $from) {
	$words = 0;
	$notags = '/(<([^>]+?)>)/';

	$item = is_array($item) ? $item : array($item);
	foreach ($item as $whatnot) {
		$content = (isset($from[$whatnot])) ? preg_replace($notags, '', trim($from[$whatnot])) . ((strlen($from[$whatnot])==0) ? '' : ' ') : '';
		if ($content) {
			$words += preg_match_all('@\s+@', $content, $m);
		}
	}
	return $words;
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
<h1>smd_article_stats</h1>

	<p>Put this tag in your article form to display info about the current article. Tags are ignored in the calculation. Note that it only displays the actual number of words entered in the article itself &#8212; if some of the content is derived from other forms, it will not be included.</p>

	<h2>Attributes</h2>

	<ul>
		<li><strong>item</strong> : list of items you want to count. The most common ones are <code>body</code>, <code>excerpt</code> or <code>title</code> to get the number of words in the relevant fields. You can supply any article field you like here (e.g. custom field: <code>item=&quot;body, book precis, book author&quot;</code>). If you don&#8217;t specify anything then the fields used will be those selected from the <a href="#smd_artstat_prefs">prefs</a></li>
		<li><strong>label</strong> : label text to output before the requested count</li>
		<li><strong>labeltag</strong> : <span class="caps">HTML</span> tag without brackets to wrap around the label</li>
		<li><strong>wraptag</strong> : <span class="caps">HTML</span> tag without brackets to wrap around the output</li>
		<li><strong>class</strong> : <span class="caps">CSS</span> classname to apply to the wraptag (default: <code>smd_article_stats</code>)</li>
	</ul>

	<h2>Admin side</h2>

	<p>On the Write panel, the number of words in the document are displayed in a stats panel. It is updated in real time as words are entered in the given fields (defined in the plugin preferences). The article ID can also be displayed; hyperlinked to the article itself if it&#8217;s live or sticky. Set the <i>Show article ID</i> preference accordingly.</p>

	<p>If you wish to move the panel to a different location on the Write panel, visit the Advanced prefs. You can then choose one of the following items from the list:</p>

	<ul>
		<li><span>Above Status</span> : (default location) above the Status box.</li>
		<li><span>Above Title</span> : above the Title box.</li>
		<li><span>Above Textile</span> : above the Textile Help twisty.</li>
		<li><span>Below Textile</span> : below the Textile Help twisty.</li>
		<li><span>Below Excerpt</span> : Immediately beneath the Excerpt.</li>
		<li><span>Below Author</span> : Beneath the author info (which is under the Excerpt). Note this position won&#8217;t appear for new articles until they are published.</li>
		<li><span>None</span> : disable the panel.</li>
	</ul>

	<p>You may also customize which fields contribute to the count by altering the value in the <i>Word count fields and <span class="caps">DOM</span> selectors</i> box. List the fields using the syntax <i>field</i> -&gt; <i><span class="caps">DOM</span> selector</i> and separate each field with a comma. For example, to count words in the body, excerpt and custom 2 fields set the preference to:</p>

<pre><code>Body -&gt; #body, Excerpt -&gt; #excerpt, custom_2 -&gt; #custom-2
</code></pre>

	<h2>Author / credits</h2>

	<p>Written by <a href="http://stefdawson.com/contact">Stef Dawson</a>. Thanks to both zem and iblastoff for the original works that this plugin borrows as its foundation.</p>

	<h2>Changelog</h2>

	<ul>
		<li>24 Aug 2009 | 0.10 | Initial release</li>
		<li>07 Nov 2009 | 0.20 | Improved counting to ignore tags and added real-time admin-side counter (both thanks speeke)</li>
		<li>22 Feb 2010 | 0.21 | Prevented error message if step mangled</li>
		<li>06 Jun 2011 | 0.22 | Added paragraph wrapper to fieldset for consistency with <span class="caps">TXP</span>&#8217;s layout (thanks philwareham)</li>
		<li>14 Feb 2012 | 0.23 | Fixed excerpt_below auto-update mechanism ; added author_below position</li>
		<li>04 Apr 2012 | 0.24 | Expanded the available fields to anything in the article (thanks Teemu)</li>
		<li>21 Nov 2012 | 0.30 | For Txp 4.5.0+ ; added visible prefs ; made smd_article_stats tag take on defaults ; display of ID is optional</li>
	</ul>
# --- END PLUGIN HELP ---
-->
<?php
}
?>