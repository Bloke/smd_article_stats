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

$plugin['version'] = '0.40';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'http://stefdawson.com/';
$plugin['description'] = 'Get article/excerpt statistics and display them to content editors and visitors';

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
$plugin['type'] = '5';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '1';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@language en, en-gb, en-us
#@smd_artstat
smd_artstat => Article statistics
smd_artstat_char_plural => chars
smd_artstat_char_singular => char
smd_artstat_fields => Word count fields and DOM selectors
smd_artstat_id => Show article ID
smd_artstat_legend => Article stats
smd_artstat_pos => Position of stats panel
smd_artstat_pos_above_sort_display => Above Sort and display
smd_artstat_pos_above_title => Above Title
smd_artstat_pos_below_author => Below Author
smd_artstat_pos_below_excerpt => Below Excerpt
smd_artstat_pos_below_sort_display => Below Sort and display
smd_artstat_set_by_admin => Set by administrator
smd_artstat_show_char => Show character count
smd_artstat_show_word => Show word count
smd_artstat_singular => Numbers treated as 'singular'
smd_artstat_word_plural => words
smd_artstat_word_singular => word
#@language fr-fr
smd_artstat => Statistiques d'article
smd_artstat_char_plural => caractères
smd_artstat_char_singular => caractère
smd_artstat_fields => Champs de décompte et sélecteur du DOM
smd_artstat_id => Afficher l'ID de l'article
smd_artstat_legend => Statistiques article
smd_artstat_pos => Emplacement dans la page
smd_artstat_pos_above_sort_display => Au dessus l'article tri et affichage
smd_artstat_pos_above_title => Au dessus du titre
smd_artstat_pos_below_author => Sous le nom d'auteur
smd_artstat_pos_below_excerpt => Sous le résumé
smd_artstat_pos_below_sort_display => Sous l'article tri et affichage
smd_artstat_set_by_admin => Défini par l'administrateur
smd_artstat_show_char => Afficher le nombre de caractères
smd_artstat_show_word => Afficher le compte de mots
smd_artstat_singular => Nombre pris au singulier
smd_artstat_word_plural => mots
smd_artstat_word_singular => mot
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_article_stats
 *
 * A Textpattern CMS plugin for counting words/characters in article fields and
 * optionally displaying them to visitors.
 *  -> Choose which fields to count on the admin side.
 *  -> Customize where you want the count to be displayed.
 *  -> Shows ID of currently edited article.
 *
 * @author Stef Dawson
 * @link   http://stefdawson.com/
 * @todo TinyMCE -- accessing fields inside iframes?
 */

if (txpinterface === 'admin') {
	$all_privs = array_keys(get_groups());
	unset($all_privs[array_search('0', $all_privs)]); // Remove 'none'.
	$all_joined = implode(',', $all_privs);

	add_privs('smd_artstat_prefs', $all_joined);
	add_privs('prefs.smd_artstat', $all_joined);
	register_callback('smd_artstat_prefs', 'prefs', '', 1);
	register_callback('smd_article_info', 'article');
	$smd_ai_prefs = smd_article_info_prefs();

	foreach ($smd_ai_prefs as $key => $prefobj) {
		register_callback('smd_article_info_pophelp', 'admin_help', $key);
	}
} elseif (txpinterface === 'public') {
    if (class_exists('\Textpattern\Tag\Registry')) {
        Txp::get('\Textpattern\Tag\Registry')
            ->register('smd_article_stats');
    }
}

/**
 * Public tag: display article statistics.
 *
 * @param  array  $atts  Tag attributes
 * @param  string $thing Tag container content
 * @return string        HTML
 */
function smd_article_stats($atts, $thing = null)
{
	global $thisarticle;

	assert_article();

	extract(lAtts(array(
		'wraptag'  => '',
		'class'    => __FUNCTION__,
		'break'    => '',
		'label'    => '',
		'labeltag' => '',
		'item'     => '',
		'type'     => 'word',
	), $atts));

	$out = array();

	// item not specified? Use the array 'keys' from the pref.
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

	$ret = smd_article_info_count($item, $thisarticle);
	$out = ($type === 'char') ? $ret['char'] : $ret['word'];

	return doLabel($label, $labeltag) . doWrap($out, $wraptag, $break, $class);
}

/**
 * Admin-side info -- auto-updated via jQuery.
 *
 * @param  string $event Textpattern event (panel)
 * @param  string $step  Textpattern step (action)
 * @return string        HTML
 */
function smd_article_info($event, $step)
{
	global $app_mode;

	extract(gpsa(array('view')));

	include_once txpath.'/publish/taghandlers.php';

	if(!$view || gps('save') || gps('publish')) {
		$view = 'text';
	}

	if ($view == 'text') {
		$screen_locs = array(
			'none'               => '',
			'excerpt_below'      => 'jq|.excerpt|after',
			'author_below'       => 'jq|.author|after',
			'sort_display_above' => 'jq|#txp-write-sort-group|before',
			'sort_display_below' => 'jq|#txp-write-sort-group|after',
			'title_above'        => 'jq|#main_content .title|prepend',
		);

		// Check hidden pref and sanitize
		$posn = get_pref('smd_artstat_pos', 'sort_display_above');
		$posn = (array_key_exists($posn, $screen_locs)) ? $posn : 'sort_display_above';
		$show_words = get_pref('smd_artstat_show_word', '1');
		$show_chars = get_pref('smd_artstat_show_char', '1');

		$placer = explode('|', $screen_locs[$posn]);
		doArray($placer, 'escape_js');

		// Split and recombine to get rid of spaces.
		// @todo Error detection if missing entries.
		$fldList = do_list(get_pref('smd_artstat_fields', 'Body -> #body, Excerpt -> #excerpt'));
		$fldAnchors = array('0'); // Placeholder since Status isn't a countable field, but we need it later.
		$db_fields = array('Status');

		foreach ($fldList as $fld) {
			$fldInfo = do_list($fld, '->');
			$db_fields[] = $fldInfo[0];

			if (isset($fldInfo[1])) {
				$fldAnchors[] = $fldInfo[1];
			}
		}

		array_shift($fldAnchors); // Goodbye Status anchor.

		$js_fields = escape_js(implode(',', $fldAnchors));
		$js_array_fields = implode(',', doArray(doArray($fldAnchors, 'escape_js'), 'doQuote'));

		$id = (empty($GLOBALS['ID']) ? gps('ID') : $GLOBALS['ID']);

		if (empty($id)) {
			$rs = $db_fields;
		} else {
			$rs = safe_row(implode(',', doArray($db_fields, 'doSlash')), 'textpattern', 'ID=' . doSlash($id));
		}

		$idlink = (get_pref('smd_artstat_id') === '1')
			? (($id && in_array($rs['Status'], array(STATUS_LIVE, STATUS_STICKY)))
				? href($id, permlinkurl_id($id))
				: $id)
			: '';

		$indiv = array(
			'word' => array(),
			'char' => array(),
		);

		$totals = array(
			'word' => 0,
			'char' => 0,
		);

		array_shift($db_fields); // Goodbye Status field.

		$info = smd_article_info_count($db_fields, $rs);

		foreach ($info as $type => $block) {
			$counter = 0;

			foreach ($block as $fld => $qty) {
				$totals[$type] += $qty;
				$indiv[$type][] = '<span class="smd_article_' . $type . '_stats_' . $counter . '">' . $qty . '</span>';
				$counter++;
			}
		}

		gTxtScript(array('smd_artstat_word_singular', 'smd_artstat_word_plural'));
		gTxtScript(array('smd_artstat_char_singular', 'smd_artstat_char_plural'));
		$singstring = get_pref('smd_artstat_singular', '1');
		$singles = do_list($singstring);
		$content = array();

		if ($show_words) {
			$content[] = '<span class="smd_article_stats_wc">' . $totals['word'] . '</span> <span class="smd_article_stats_wd">' . (in_array($totals['word'], $singles) ? gTxt('smd_artstat_word_singular') : gTxt('smd_artstat_word_plural')) . '</span>';

			if (count($indiv['word']) > 1) {
				$content[] = ' ( ' . implode(' / ', $indiv['word']) . ' )';
			}
		}

		if ($show_chars) {
			$content[] = ($content ? ' | ' : '') . '<span class="smd_article_stats_cc">' . $totals['char'] . '</span> <span class="smd_article_stats_cd">' . (in_array($totals['char'], $singles) ? gTxt('smd_artstat_char_singular') : gTxt('smd_artstat_char_plural')) . '</span>';

			if (count($indiv['char']) > 1) {
				$content[] = ' ( ' . implode(' / ', $indiv['char']) . ' )';
			}
		}

		if ($idlink) {
			$content[] = ($content ? ' | ' : '') . gTxt('id') . n . $idlink;
		}

		$out1 = escape_js(
			defined('PREF_PLUGIN')
				? wrapGroup('smd_artstat', implode(n, $content), 'smd_artstat')
				: '<fieldset><legend>'.gTxt('smd_artstat_legend').'</legend><p>' . implode(n, $content) . '</p></fieldset>'
		);

		$out2 = script_js(<<<EOJS
jQuery(function() {
	var singlist = [{$singstring}];

	jQuery("{$js_fields}").keyup(function() {
		var flds = [{$js_array_fields}];
		var wds = 0;
		var chs = 0;
		var content = '';

		for (idx = 0; idx < flds.length; idx++) {
			if (jQuery(flds[idx]).length > 0) {
				content = jQuery(flds[idx]).val();
				content += (content.length > 0) ? " " : "";
				content = content.replace(/(<([^>]+)>)/ig,"");

				char_count = content.length-1;
				word_count = content.split(/\s+/).length-1;

				jQuery(".smd_article_word_stats_"+idx).text(word_count);
				jQuery(".smd_article_char_stats_"+idx).text(char_count);
				wds += word_count;
				chs += char_count;
			}
		}

		jQuery(".smd_article_stats_wc").text(wds);
		jQuery(".smd_article_stats_cc").text(chs);
		jQuery(".smd_article_stats_wd").text(((jQuery.inArray(wds, singlist) > -1) ? textpattern.gTxt('smd_artstat_word_singular') : textpattern.gTxt('smd_artstat_word_plural')));
		jQuery(".smd_article_stats_cd").text(((jQuery.inArray(chs, singlist) > -1) ? textpattern.gTxt('smd_artstat_char_singular') : textpattern.gTxt('smd_artstat_char_plural')));
	}).keyup();
});
EOJS
		);

		if ($placer[0] === 'jq' && $app_mode !== 'async') {
			echo '<script type="text/javascript">jQuery(function() { jQuery("' . $placer[1] . '").' . $placer[2] . '(\'' . $out1 . '\'); });</script>' . $out2;
		}
	}
}

/**
 * Get pophelp content from stefdawson.com
 *
 * @param  string $evt  Textpattern event (panel)
 * @param  string $stp  Textpattern step (action)
 * @param  string $ui   Default ui content
 * @param  array  $vars The variables in use for the current article
 * @return string       Help text
 */
function smd_article_info_pophelp($evt, $stp, $ui, $vars)
{
	return str_replace(HELP_URL, 'http://stefdawson.com/downloads/support/', $ui);
}

/**
 * Install prefs if they don't already exist.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 */
function smd_artstat_prefs($evt, $stp)
{
	$smd_ai_prefs = smd_article_info_prefs();

	foreach ($smd_ai_prefs as $key => $prefobj) {
		if (get_pref($key) === '') {
			set_pref($key, doSlash($prefobj['default']), 'smd_artstat', $prefobj['type'], $prefobj['html'], $prefobj['position'], $prefobj['visibility']);
		}
	}
}

/**
 * Only render these prefs if enough privs exist.
 *
 * Restricted message otherwise.
 *
 * @param  string $key The preference key being displayed
 * @param  string $val The current preference value
 * @return string      HTML
 */
function smd_artstat_restricted($key, $val)
{
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

/**
 * Render the position pref.
 *
 * @param  string $key The preference key being displayed
 * @param  string $val The current preference value
 * @return string      HTML
 */
function smd_artstat_pos($key, $val)
{
	$smd_ai_prefs = smd_article_info_prefs();
	$obj = $smd_ai_prefs[$key];
	return selectInput($key, $obj['content'], $val);
}

/**
 * Settings for the plugin.
 *
 * @return array  Preference set
 */
function smd_article_info_prefs()
{
	$smd_ai_prefs = array(
		'smd_artstat_fields' => array(
			'html'       => 'smd_artstat_restricted',
			'type'       => PREF_PLUGIN,
			'position'   => 10,
			'default'    => 'Body -> #body, Excerpt -> #excerpt',
			'group'      => 'smd_artstat_settings',
			'visibility' => PREF_GLOBAL,
		),
		'smd_artstat_pos' => array(
			'html'       => 'smd_artstat_pos',
			'type'       => PREF_PLUGIN,
			'position'   => 20,
			'content'    => array(
				'none'                  => gTxt('none'),
				'title_above'           => gTxt('smd_artstat_pos_above_title'),
				'excerpt_below'         => gTxt('smd_artstat_pos_below_excerpt'),
				'author_below'          => gTxt('smd_artstat_pos_below_author'),
				'sort_display_above'    => gTxt('smd_artstat_pos_above_sort_display'),
				'sort_display_below'    => gTxt('smd_artstat_pos_below_sort_display'),
				),
			'default'    => 'sort_display_above',
			'group'      => 'smd_artstat_settings',
			'visibility' => PREF_PRIVATE,
		),
		'smd_artstat_singular' => array(
			'html'       => 'smd_artstat_restricted',
			'type'       => PREF_PLUGIN,
			'position'   => 30,
			'default'    => '1',
			'group'      => 'smd_artstat_settings',
			'visibility' => PREF_GLOBAL,
		),
        'smd_artstat_show_word' => array(
            'html'       => 'yesnoradio',
            'type'       => PREF_PLUGIN,
            'position'   => 40,
            'default'    => '1',
            'group'      => 'smd_artstat_settings',
            'visibility' => PREF_PRIVATE,
        ),
        'smd_artstat_show_char' => array(
            'html'       => 'yesnoradio',
            'type'       => PREF_PLUGIN,
            'position'   => 50,
            'default'    => '0',
            'group'      => 'smd_artstat_settings',
            'visibility' => PREF_PRIVATE,
        ),
        'smd_artstat_id' => array(
            'html'       => 'yesnoradio',
            'type'       => PREF_PLUGIN,
            'position'   => 60,
            'default'    => '0',
            'group'      => 'smd_artstat_settings',
            'visibility' => PREF_PRIVATE,
        ),
	);

	return $smd_ai_prefs;
}

/**
 * Library function to count words in the given field items.
 *
 * @param  string|array $item The field(s) to count
 * @param  array        $from The structure containing the data
 * @return array
 * @todo   Filter out common Textile markup somehow? But what about when multi textfilters hit the streets?
 */
function smd_article_info_count($item, $from)
{
	$words = array();
	$chars = array();
	$notags = '/(<([^>]+?)>)/';

	$item = is_array($item) ? $item : array($item);

	foreach ($item as $whatnot) {
		$content = (isset($from[$whatnot])) ? preg_replace($notags, '', trim($from[$whatnot])) . ((strlen($from[$whatnot])==0) ? '' : ' ') : '';

		if ($content) {
			if (!isset($words[$whatnot])) {
				$words[$whatnot] = 0;
			}

			if (!isset($chars[$whatnot])) {
				$chars[$whatnot] = 0;
			}

			$words[$whatnot] += preg_match_all('@\s+@', $content, $m);
			$chars[$whatnot] += strlen($content) - 1;
		}
	}

	return array('word' => $words, 'char' => $chars);
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_article_stats

Put this tag in your article form to display info about the current article. Tags are ignored in the calculation, as far as possible. Note that it only displays the actual number of words entered in the article itself -- if some of the content is derived from other forms, it will not be included. The same goes for character counts: if there is markup or random content in the field you choose to tally, the plugin will make a best guess after it strips tags from the field.

h2. Attributes

* *item* : list of items you want to count. The most common ones are @body@, @excerpt@ or @title@ to get the number of words in the relevant fields. You can supply any article field you like here (e.g. custom field: @item="body, book precis, book author"@). If you don't specify anything then the fields used will be those selected from the prefs.
* *type* : flavour of information you wish to display. Either @word@ (the default) or @char@.
* *label* : label text to output before the requested count.
* *labeltag* : HTML tag without brackets to wrap around the label.
* *wraptag* : HTML tag without brackets to wrap around the output.
* *class* : CSS classname to apply to the wraptag (default: @smd_article_stats@).

h2. Admin side

On the Write panel, the number of words/chars in the document are displayed in a stats panel. It is updated in real time as data is entered in the given fields (defined in the plugin preferences). The article ID can also be displayed; hyperlinked to the article itself if it's live or sticky. Set the _Show article ID_ preference accordingly.

If you wish to move the panel to a different location on the Write panel, visit the Advanced prefs. You can then choose one of the following items from the list:

* %Above Sort and display% : (default location) above the 'Sort and display' box.
* %Below Sort and display% : below the 'Sort and display' box.
* %Above Title% : above the Title box.
* %Below Excerpt% : Immediately beneath the Excerpt.
* %Below Author% : Beneath the author info (which is under the Excerpt). Note this position won't appear for new articles until they are published.
* %None% : disable the panel.

You may also customize which fields contribute to the count by altering the value in the _Word count fields and DOM selectors_ box. List the fields using the syntax _field_ -> _DOM selector_ and separate each field with a comma. For example, to count words in the body, excerpt and custom 2 fields, set the preference to:

bc. Body -> #body, Excerpt -> #excerpt, custom_2 -> #custom-2

h2. Author / credits

Written by "Stef Dawson":http://stefdawson.com/contact. Thanks to both zem and iblastoff for the original works that this plugin borrows as its foundation.

h2. Changelog

* 17 Mar 2016 | 0.40 | For Txp 4.6.0+ ; Added character count support ; fixed the default DOM anchors for new Write panel layout
* 21 Nov 2012 | 0.30 | For Txp 4.5.0+ ; added visible prefs ; made smd_article_stats tag take on defaults ; display of ID is optional
* 04 Apr 2012 | 0.24 | Expanded the available fields to anything in the article (thanks Teemu)
* 14 Feb 2012 | 0.23 | Fixed excerpt_below auto-update mechanism ; added author_below position
* 06 Jun 2011 | 0.22 | Added paragraph wrapper to fieldset for consistency with TXP's layout (thanks philwareham)
* 22 Feb 2010 | 0.21 | Prevented error message if step mangled
* 07 Nov 2009 | 0.20 | Improved counting to ignore tags and added real-time admin-side counter (both thanks speeke)
* 24 Aug 2009 | 0.10 | Initial release
# --- END PLUGIN HELP ---
-->
<?php
}
?>