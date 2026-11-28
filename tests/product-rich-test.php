<?php
/**
 * Test for FisHotel_Product_Facts::rich() (inc/product-facts.php) — the
 * description as the product page prints it since v1.30.0: paragraphs,
 * lists, bold, italics, links and quotes kept, everything else dropped,
 * the "Fun Facts" block taken out as rows with their lead-ins, old-style
 * labelled lines hidden when their field is filled, and the plain text of
 * prose() whenever the markup is beyond the rules. Also the product page
 * itself: a medication page never calls rich(). No WordPress, no database.
 *
 *   php tests/product-rich-test.php
 *
 * All fish, facts and text below are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/inc/product-facts.php';

$failures = 0;
function check( $label, $ok, $detail = '' ) {
	global $failures;
	if ( $ok ) {
		echo "  ok   $label\n";
	} else {
		$failures++;
		echo "  FAIL $label" . ( $detail !== '' ? " — $detail" : '' ) . "\n";
	}
}
function j( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }

$F = 'FisHotel_Product_Facts';

/** rich() as one comparable value: [ html, title, [ [ lead, html ], … ] ]. */
function r( $raw, array $filled = [] ) {
	$out   = FisHotel_Product_Facts::rich( $raw, $filled );
	$facts = [];
	foreach ( $out['fun_facts'] as $fact ) {
		$facts[] = [ $fact['lead'], $fact['html'] ];
	}
	return [ $out['html'], $out['fun_title'], $facts ];
}
/** One check: what rich() makes of $raw is exactly html + title + facts, and it is not the plain fallback. */
function same( $label, $raw, $filled, $html, $title = '', array $facts = [] ) {
	$got = r( $raw, $filled );
	$out = FisHotel_Product_Facts::rich( $raw, $filled );
	check( $label, [ $html, $title, $facts ] === $got && false === $out['plain'], j( $got ) . ( $out['plain'] ? ' (plain fallback)' : '' ) );
}
/** Every tag in a piece of output. */
function tags_in( $html ) {
	preg_match_all( '~<[^>]*>~', $html, $m );
	return $m[0];
}

// ── The Fun Facts block ─────────────────────────────────────────────────
echo "fun facts in a blockquote\n";

// The way the classic editor stores a quote with a list in it.
same( 'blockquote + list → card, two paragraphs left',
	"First paragraph.\n\nSecond paragraph.\n<blockquote>Fun Facts:\n<ul>\n \t<li><strong>Night Owl:</strong> It naps all day.</li>\n \t<li>Sand Sifter: It moves a cup of sand a day.</li>\n</ul>\n</blockquote>",
	[],
	'<p>First paragraph.</p><p>Second paragraph.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps all day.' ], [ 'Sand Sifter', 'It moves a cup of sand a day.' ] ]
);
same( 'blockquote, bold label, facts as plain lines',
	"Intro.\n<blockquote><strong>Fun Facts:</strong>\nNight Owl: It naps all day.\nSand Sifter: It moves sand.</blockquote>",
	[],
	'<p>Intro.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps all day.' ], [ 'Sand Sifter', 'It moves sand.' ] ]
);
same( 'blockquote with <p> lines, bold lead-in, a fact with no colon',
	'<p>Intro.</p><blockquote><p>Fun Facts:</p><p><b>Night Owl</b>: It naps.</p><p>It has no eyelids.</p></blockquote><p>After.</p>',
	[],
	'<p>Intro.</p><p>After.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ], [ '', 'It has no eyelids.' ] ]
);
same( 'blockquote with <br> lines',
	"<blockquote>Fun Facts:<br>Night Owl: It naps.<br />\nSand Sifter: It sifts.</blockquote>",
	[],
	'',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ], [ 'Sand Sifter', 'It sifts.' ] ]
);
same( 'lines of the quote above the label stay a quote',
	"<blockquote>A saying.\n\nFun Facts:\nOne: first.</blockquote>",
	[],
	'<blockquote><p>A saying.</p></blockquote>',
	'Fun Facts',
	[ [ 'One', 'first.' ] ]
);
same( 'block-editor comments around the quote',
	'<!-- wp:paragraph --><p>Block text.</p><!-- /wp:paragraph --><!-- wp:quote --><blockquote class="wp-block-quote"><p>Fun Facts:</p><ul><li>One: first.</li></ul></blockquote><!-- /wp:quote -->',
	[],
	'<p>Block text.</p>',
	'Fun Facts',
	[ [ 'One', 'first.' ] ]
);

echo "fun facts with no blockquote\n";
same( 'label, then a list',
	"Intro.\n\nFun Facts:\n<ul><li>Night Owl: It naps.</li><li>A fact with no colon</li></ul>\nShips in a breather bag.",
	[],
	'<p>Intro.</p><p>Ships in a breather bag.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ], [ '', 'A fact with no colon' ] ]
);
same( 'label, then lines in the same paragraph',
	"Intro.\n\nFun Facts:\nNight Owl: It naps.\nSand Sifter: It sifts.\n\nShips in a breather bag.",
	[],
	'<p>Intro.</p><p>Ships in a breather bag.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ], [ 'Sand Sifter', 'It sifts.' ] ]
);
same( 'label, then "lead-in: sentence" paragraphs, up to the first plain one',
	"Intro.\n\nFun Facts:\n\nNight Owl: It naps.\n\nSand Sifter: It sifts.\n\nShips in a breather bag.",
	[],
	'<p>Intro.</p><p>Ships in a breather bag.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ], [ 'Sand Sifter', 'It sifts.' ] ]
);
same( 'label on its own, the facts in the blockquote under it',
	"Intro.\n\nFun Facts:\n<blockquote><ul><li>Night Owl: It naps.</li><li>No colon here</li></ul></blockquote>\nAfter.",
	[],
	'<p>Intro.</p><p>After.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ], [ '', 'No colon here' ] ]
);
same( 'label typed as a heading over a list',
	'<h3>Fun Facts</h3><ul><li><strong>Night Owl:</strong> It naps.</li></ul><p>After.</p>',
	[],
	'<p>After.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ] ]
);
// The text tests/product-facts-test.php pins for prose().
same( 'one fact on the label\'s own line',
	"A bright little test fish that settles in fast.\n\nFun Facts: It changes colour at night.\n\nShips in a breather bag.",
	[],
	'<p>A bright little test fish that settles in fast.</p><p>Ships in a breather bag.</p>',
	'Fun Facts',
	[ [ '', 'It changes colour at night.' ] ]
);
same( '"Fun Fact" typed in the singular keeps its title; a second label is left as typed',
	"Fun Fact: One.\n\nMiddle.\n\nFun Facts: Two.",
	[],
	'<p>Middle.</p><p>Fun Facts: Two.</p>',
	'Fun Fact',
	[ [ '', 'One.' ] ]
);
same( 'label as the very last line, nothing under it → no card, no heading',
	"Intro paragraph.\n\nFun Facts:",
	[],
	'<p>Intro paragraph.</p>'
);
same( 'label with text the rules cannot read as facts → nothing is removed',
	"Fun Facts:\n\nThis fish is simply a delight to keep and that is all there is to it.",
	[],
	'<p>Fun Facts:</p><p>This fish is simply a delight to keep and that is all there is to it.</p>'
);

// With no blockquote around them, the card ends at a line that is a fact
// label of its own ("Temperament: …"): that line is an old-style fact, and
// the hide rule deals with it exactly as before v1.30.0. Three shapes.
echo "fun facts with no blockquote, old-style lines under them\n";
$every = [ 'scientific_name', 'common_names', 'max_length', 'min_tank_size', 'temperament', 'reef_safe', 'region', 'foods_feeding', 'habitat' ];
same( 'shape 1 — lines of one paragraph: the fact is a fact, the labelled lines stay hidden',
	"<p>Description: It glows a little.<br>Fun Facts: It hums when it courts.<br>Habitat: Sandy lagoons.<br>Temperament: Peaceful<br>Reef Safe: Yes<br>Maximum Length: 3 inches</p>",
	$every,
	'<p>It glows a little.</p>',
	'Fun Facts',
	[ [ '', 'It hums when it courts.' ] ]
);
same( 'shape 1 — the label alone, labelled lines only: no card, no heading, nothing printed twice',
	"A calm fish.\n\nFun Facts:\nScientific Name: Testus fishus\nMaximum Length: 4 inches\nTemperament: Peaceful",
	$every,
	'<p>A calm fish.</p>'
);
same( 'shape 1 — real facts first, then labelled lines: the card stops at the first label',
	"A calm fish.\n\nFun Facts:\nNight Owl: It naps.\nTemperament: Peaceful\nReef Safe: Yes",
	$every,
	'<p>A calm fish.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ] ]
);
same( 'shape 2 — a list under the label: labelled items are not facts',
	'<p>A calm fish.</p><p>Fun Facts:</p><ul><li>Night Owl: It naps.</li><li>Temperament: Peaceful</li><li>Region: Red Sea</li></ul>',
	$every,
	'<p>A calm fish.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ] ]
);
same( 'shape 2 — a list of labelled items only: no card, no heading',
	'<p>A calm fish.</p><p>Fun Facts:</p><ul><li>Scientific Name: Testus fishus</li><li>Minimum Aquarium Size: 30 gallons</li></ul>',
	$every,
	'<p>A calm fish.</p>'
);
same( 'shape 3 — "Label: text" paragraphs under the label: none is a fact',
	"<p>A calm fish.</p><p>Fun Facts:</p><p>Scientific Name: Testus fishus</p><p>Habitat and Behavior: Hovers near rockwork.</p><p>Foods and Feeding Habits: Mysis twice a day.</p>",
	$every,
	'<p>A calm fish.</p>'
);
same( 'shape 3 — real facts, then labelled paragraphs',
	"A calm fish.\n\nFun Facts:\n\nNight Owl: It naps.\n\nTemperament: Peaceful\n\nRegion: Red Sea",
	$every,
	'<p>A calm fish.</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ] ]
);
same( 'a second "Fun Facts:" with only hidden lines under it does not stay behind as a heading over nothing',
	"A calm fish.\n\nFun Facts: It hums.\n\nFun Facts:\n\nScientific Name: Testus fishus\n\nMinimum Aquarium Size: 30 gallons",
	$every,
	'<p>A calm fish.</p>',
	'Fun Facts',
	[ [ '', 'It hums.' ] ]
);
same( 'a labelled line whose field is EMPTY stays readable in the text, not in the card',
	"A calm fish.\n\nFun Facts:\nNight Owl: It naps.\nTemperament: Peaceful",
	[],
	'<p>A calm fish.</p><p>Temperament: Peaceful</p>',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ] ]
);
same( 'a fact on the label\'s own line: a plain line after it stays text',
	"Intro.\n\nFun fact: It hums when it courts.\nShips in a breather bag.",
	[],
	'<p>Intro.</p><p>Ships in a breather bag.</p>',
	'Fun Fact',
	[ [ '', 'It hums when it courts.' ] ]
);
same( 'a fact on the label\'s own line: "Lead-in: sentence" lines after it are facts',
	"Intro.\n\nFun Facts: It hums when it courts.\nNight Owl: It naps.\nShips in a breather bag.",
	[],
	'<p>Intro.</p><p>Ships in a breather bag.</p>',
	'Fun Facts',
	[ [ '', 'It hums when it courts.' ], [ 'Night Owl', 'It naps.' ] ]
);
// The owner grouped them: inside a blockquote nothing is second-guessed.
same( 'inside a blockquote a labelled line is still a fun fact',
	"<blockquote>Fun Facts:\nNight Owl: It naps.\nTemperament: It sulks when moved.</blockquote>",
	$every,
	'',
	'Fun Facts',
	[ [ 'Night Owl', 'It naps.' ], [ 'Temperament', 'It sulks when moved.' ] ]
);

// The label does not have to be typed exactly "Fun Facts:".
echo "the label typed a little differently\n";
$quote = '<ul><li>Night Owl: It naps.</li><li>Sand Sifter: It sifts.</li></ul></blockquote>';
$two   = [ [ 'Night Owl', 'It naps.' ], [ 'Sand Sifter', 'It sifts.' ] ];
same( '"Fun Facts about this fish:" — its words become the heading', "<blockquote>Fun Facts about this fish:$quote", [], '', 'Fun Facts about this fish', $two );
same( '"Fun Facts!"', "<blockquote><strong>Fun Facts!</strong>$quote", [], '', 'Fun Facts', $two );
same( 'a symbol typed in front', "<blockquote>★ Fun Facts:$quote", [], '', 'Fun Facts', $two );
same( 'capitals, no colon, on its own line', "<blockquote><p>FUN FACTS</p>$quote", [], '', 'Fun Facts', $two );
same( '"Fun fact about the Ember Wrasse: …" with the fact on the same line',
	"Intro.\n\nFun fact about the Ember Wrasse: it hums.",
	[],
	'<p>Intro.</p>',
	'Fun Fact about the Ember Wrasse',
	[ [ '', 'it hums.' ] ]
);
same( 'a sentence that only begins with the words is left alone',
	"Fun facts are hard to come by for a fish this shy and this small.\n\nNight Owl: It naps.",
	[],
	'<p>Fun facts are hard to come by for a fish this shy and this small.</p><p>Night Owl: It naps.</p>'
);

echo "lead-ins\n";
same( 'nested markup, times, links, a bold that runs past the colon, a long clause, a nested list',
	'<blockquote>Fun Facts:<ul><li><strong><em>Night Owl:</em></strong> It wakes at 10:30 and reads <a href="/blog/">our blog</a>.</li><li>Feeds at 10:30 sharp</li><li><strong>Bold fact: with the bold</strong> running on</li><li>A very long opening clause that is really a sentence and not a lead-in at all: so it stays whole</li><li>Outer fact<ul><li>Inner: nested fact</li></ul></li></ul></blockquote>',
	[],
	'',
	'Fun Facts',
	[
		[ 'Night Owl', 'It wakes at 10:30 and reads <a href="/blog/">our blog</a>.' ],
		[ '', 'Feeds at 10:30 sharp' ],
		[ 'Bold fact', 'with the bold running on' ],
		[ '', 'A very long opening clause that is really a sentence and not a lead-in at all: so it stays whole' ],
		[ '', 'Outer fact' ],
		[ 'Inner', 'nested fact' ],
	]
);
$a40 = str_repeat( 'a', 40 );
$a41 = str_repeat( 'a', 41 );
same( '40 characters and 6 words are lead-ins; 41 and 7 are not; typed bullets go',
	"<blockquote>Fun Facts:<ul><li>$a40: ok.</li><li>$a41: long.</li><li>one two three four five six: ok.</li><li>one two three four five six seven: long.</li><li>http://example.test is a link</li><li>Ends with colon:</li><li>- Bullet Lead: dashed.</li><li>• Dot Lead: dotted.</li></ul></blockquote>",
	[],
	'',
	'Fun Facts',
	[
		[ $a40, 'ok.' ],
		[ '', "$a41: long." ],
		[ 'one two three four five six', 'ok.' ],
		[ '', 'one two three four five six seven: long.' ],
		[ '', 'http://example.test is a link' ],
		[ '', 'Ends with colon:' ],
		[ 'Bullet Lead', 'dashed.' ],
		[ 'Dot Lead', 'dotted.' ],
	]
);
check( 'split_fact(): a lead-in is plain text, never HTML',
	[ 'lead' => 'A & B', 'html' => 'fine.' ] === $F::split_fact( '<em>A &amp; B</em>: fine.' ),
	j( $F::split_fact( '<em>A &amp; B</em>: fine.' ) )
);

// ── Old-style labelled lines ────────────────────────────────────────────
echo "old-style lines\n";
same( 'a fun fact that starts with a known label stays in the card when that field is filled',
	"Intro.\n<blockquote>Fun Facts:<ul><li>Habitat: It sleeps in the sand.</li><li>Region: Found on one reef only.</li></ul></blockquote>",
	[ 'habitat', 'region' ],
	'<p>Intro.</p>',
	'Fun Facts',
	[ [ 'Habitat', 'It sleeps in the sand.' ], [ 'Region', 'Found on one reef only.' ] ]
);
$oldish = '<p>Scientific Name: Testus fishus<br>Maximum Length: 4"<br>Reef Safe: Yes</p><p>A calm fish.</p><p>Temperament: Peaceful</p>';
same( 'labelled lines whose field is filled are hidden, the others stay',
	$oldish,
	[ 'scientific_name', 'max_length', 'reef_safe' ],
	'<p>A calm fish.</p><p>Temperament: Peaceful</p>'
);
same( 'nothing filled → nothing hidden',
	$oldish,
	[],
	'<p>Scientific Name: Testus fishus<br>Maximum Length: 4"<br>Reef Safe: Yes</p><p>A calm fish.</p><p>Temperament: Peaceful</p>'
);
same( 'a leading "Description:" goes, blank lines do not pile up',
	"Description: A hardy fish.\n\n\n\nSecond.",
	[],
	'<p>A hardy fish.</p><p>Second.</p>'
);
// The description tests/product-facts-test.php uses, every field filled.
$old = "<p>Description: A bright little test fish that settles in fast.</p>\n"
	. "<p>Scientific Name: Testus brightus<br>\n"
	. "Common Names: Bright Test Fish, Testie<br>\n"
	. "Maximum Length: 3 inches (7.5 cm)<br>\n"
	. "Minimum Aquarium Size: 30 gallons<br>\n"
	. "Reef Safety: Yes<br>\n"
	. "Temperament: Peaceful</p>\n"
	. "<p>Foods and Feeding Habits: Frozen mysis twice a day.</p>\n"
	. "<p>Habitat and Behavior: Hovers near rockwork.</p>\n"
	. "<p>Fun Facts: It changes colour at night.</p>\n"
	. "<p>Ships in a breather bag.</p>";
$all = [ 'scientific_name', 'common_names', 'max_length', 'min_tank_size', 'temperament', 'reef_safe', 'foods_feeding', 'habitat' ];
same( 'a whole old-style description, every field filled',
	$old,
	$all,
	'<p>A bright little test fish that settles in fast.</p><p>Ships in a breather bag.</p>',
	'Fun Facts',
	[ [ '', 'It changes colour at night.' ] ]
);
check( 'prose() itself is unchanged by all this',
	"A bright little test fish that settles in fast.\n\nFun Facts: It changes colour at night.\n\nShips in a breather bag." === $F::prose( $old, $all ),
	j( $F::prose( $old, $all ) )
);

// ── The rest of the description ─────────────────────────────────────────
echo "formatting that is kept\n";
same( 'heading, italics, list, a plain quote, a link (href only), an unsafe link (words only)',
	"<h3>Care</h3>Easy fish. <em>Not</em> for nano tanks.\n<ul><li>Feed twice a day</li><li>Keep a lid on</li></ul>\n<blockquote>Our favourite fish in the building.</blockquote>\nSee <a href=\"https://example.test/guide\" target=\"_blank\" onclick=\"x()\">the guide</a> and <a href=\"javascript:alert(1)\">this</a>.",
	[],
	'<p><strong>Care</strong></p><p>Easy fish. <em>Not</em> for nano tanks.</p><ul><li>Feed twice a day</li><li>Keep a lid on</li></ul><blockquote><p>Our favourite fish in the building.</p></blockquote><p>See <a href="https://example.test/guide">the guide</a> and this.</p>'
);
same( 'numbered list, nested list, nested quote',
	'<blockquote>Outer <blockquote>inner</blockquote> again</blockquote><ol><li>one</li><li>two<ul><li>two-a</li></ul></li></ol>',
	[],
	'<blockquote><p>Outer</p><p>inner</p><p>again</p></blockquote><ol><li>one</li><li>two</li><li>two-a</li></ol>'
);
same( 'a table and a div become paragraphs',
	'<table><tr><th>Size</th><td>Small</td></tr></table><hr>After<div>Div text</div>',
	[],
	'<p>Size</p><p>Small</p><p>After</p><p>Div text</p>'
);
same( 'bold that runs over a line break is closed and opened again',
	"<strong>Bold start\nstill bold</strong> plain",
	[],
	'<p><strong>Bold start</strong><br><strong>still bold</strong> plain</p>'
);
same( 'tags closed in the wrong order still come out closed',
	'<p>One <strong>bold <em>both</p><p>Two</em> still</strong> end',
	[],
	'<p>One <strong>bold <em>both</em></strong></p><p><strong><em>Two</em> still</strong> end</p>'
);
same( 'entities are read once and written once',
	'<p>Fish &amp; chips &lt;script&gt;alert(1)&lt;/script&gt; &quot;q&quot; caf&eacute; 5&nbsp;cm</p>',
	[],
	'<p>Fish &amp; chips &lt;script&gt;alert(1)&lt;/script&gt; "q" café 5 cm</p>'
);
same( 'shortcodes are not run: printed as the typed words',
	"Intro [gallery ids=\"1,2\"] text.\n\n[fishotel_tracking]",
	[],
	'<p>Intro [gallery ids="1,2"] text.</p><p>[fishotel_tracking]</p>'
);
same( 'an empty description gives nothing', "  \n\n ", [], '' );

// ── Hostile markup ──────────────────────────────────────────────────────
echo "hostile markup\n";
same( 'script, image with onerror, iframe, styled span, a loose "<"',
	'Hello<script>alert(1)</script> <img src=x onerror=y> <iframe src="//x"></iframe>world <span style="color:red">red</span> &amp; 5 < 6',
	[],
	'<p>Hello world red &amp; 5 &lt; 6</p>'
);
same( 'javascript:, data: and vbscript: links in any spelling lose the link',
	'See <a href="JaVaScRiPt:alert(1)">this</a> and <a href="&#106;avascript:alert(1)">that</a> and <a href=" javascript:alert(1)">x</a> <a href="data:text/html,x">d</a> <a href="vbscript:x">v</a>.',
	[],
	'<p>See this and that and x d v.</p>'
);
same( 'every attribute except href goes',
	'<p onclick="x()" style="color:red" class="a">Hi <b onmouseover="y()">bold</b> <a href="https://example.test/a?b=1&amp;c=2" onclick="z()" target="_blank" class="k">link</a></p>',
	[],
	'<p>Hi <b>bold</b> <a href="https://example.test/a?b=1&amp;c=2">link</a></p>'
);
same( 'a link target with a quote in it is dropped; a space is encoded',
	'<a href=\'/x"onmouseover="alert(1)\'>q</a> <a href=/plain>p</a> <a href="/a b">s</a>',
	[],
	'<p>q <a href="/plain">p</a> <a href="/a%20b">s</a></p>'
);
same( 'svg, math, object, embed and form fields leave only their words',
	'A <svg onload="alert(1)"><circle/></svg> B <math><mi>x</mi></math> <object data="x"></object><embed src="x"><form action="/x"><input name="a" value="v"><button>Go</button></form>',
	[],
	'<p>A B x Go</p>'
);
same( 'a script tag split to dodge filters prints as harmless words',
	'A <scr<script>x</script>ipt>alert(1)</scr<script>y</script>ipt> B',
	[],
	'<p>A alert(1) B</p>'
);
same( 'a link around a picture, and an empty link, leave nothing behind',
	'See <a href="/x"><img src="/a.png" alt="alt"></a> and <a href="/y"> </a>done.',
	[],
	'<p>See and done.</p>'
);

same( 'the link target is the href attribute itself, not the word "href=" inside another attribute',
	'<a title="see href=https://wrong.example.test/ here" href="https://right.example.test/">link</a>',
	[],
	'<p><a href="https://right.example.test/">link</a></p>'
);
same( 'a link that is never closed ends with its paragraph',
	'<p>See <a href="/guide">the guide.</p><p>Second paragraph.</p><ul><li>Item</li></ul>',
	[],
	'<p>See <a href="/guide">the guide.</a></p><p>Second paragraph.</p><ul><li>Item</li></ul>'
);
same( 'a tag opened twice is opened once',
	'<p><strong><strong><b>Very</b></strong></strong> bold</p>',
	[],
	'<p><strong><b>Very</b></strong> bold</p>'
);
same( 'entities typed as text are written back as typed, in a fact too',
	'<blockquote>Fun Facts:<ul><li><strong>Tag Talk: It is written &amp;lt;b&amp;gt;</strong> in code</li></ul></blockquote><p>Type &amp;lt; for a bracket.</p>',
	[],
	'<p>Type &amp;lt; for a bracket.</p>',
	'Fun Facts',
	[ [ 'Tag Talk', 'It is written &amp;lt;b&amp;gt; in code' ] ]
);
same( 'a list item typed over two lines: a labelled second line is hidden as before',
	"<ul><li>Feed twice a day\nTemperament: Peaceful</li><li>Keep a lid\non the tank</li></ul>",
	[ 'temperament' ],
	'<ul><li>Feed twice a day</li><li>Keep a lid on the tank</li></ul>'
);

// What comes out is never many times what was typed: a slip (a forgotten
// </strong>) makes the rest bold, as a browser would; markup built to blow
// the page up gets the plain text instead.
$slip = '<p>' . str_repeat( '<strong>', 300 ) . 'Bold from here.</p>' . str_repeat( "<p>Another line.</p>\n", 300 );
$t0   = microtime( true );
$out  = $F::rich( $slip, [] );
$took = microtime( true ) - $t0;
check( '300 unclosed bold tags + 300 lines: output stays small and quick',
	strlen( $out['html'] ) < 3 * strlen( $slip ) && $took < 2 && false !== strpos( $out['html'], 'Another line.' ),
	strlen( $out['html'] ) . ' bytes from ' . strlen( $slip ) . ', ' . round( $took, 2 ) . 's'
);
$bomb = '<p><a href="/' . str_repeat( 'x', 2000 ) . '">' . str_repeat( "line<br>\n", 3000 ) . '</p>';
$t0   = microtime( true );
$out  = $F::rich( $bomb, [] );
$took = microtime( true ) - $t0;
check( 'one long link over 3,000 lines: the plain text is printed instead',
	true === $out['plain'] && strlen( $out['html'] ) < 3 * strlen( $bomb ) && $took < 2 && false === strpos( $out['html'], '<a' ),
	strlen( $out['html'] ) . ' bytes from ' . strlen( $bomb ) . ', ' . round( $took, 2 ) . 's, plain=' . j( $out['plain'] )
);

// Whatever comes in, only the allowed tags come out — each written exactly
// one way, with nothing but an href on a link.
$allowed  = '~^(?:</?(?:p|strong|b|em|i|ul|ol|li|blockquote)>|<br>|<br />|</a>|<a href="[^"<>]*">)$~';
$nasty    = [
	'<p>ok</p><script>alert(1)</script><style>p{}</style>',
	'<img src=x onerror=alert(1)>text<iframe srcdoc="<script>alert(1)</script>"></iframe>',
	'<a href="javascript:alert(1)" onclick="alert(2)">a</a><a href="https://example.test" style="x" target="_blank">b</a>',
	'<blockquote onclick="x">Fun Facts:<ul><li><a href="javascript:x">Bad: link</a></li><li><img src=x onerror=y>Pic: fact <b onclick="z">bold</b></li></ul></blockquote>',
	'<div><h1 onclick="x">Title</h1><p style="x">Para<br>line</p></div><table><tr><td>cell</td></tr></table>',
	'<p>One <strong>bold <em>both</p><p>Two</em> still</strong> end',
	'Text <!-- note and more',
	'I <3 this fish. <b>It</b> is great.',
	'A <a href="/x" title="a>b">link</a> B',
	"Bad \xC3\x28 bytes <b>bold</b>",
	'<<<>>><b><i></b></i><a><a href="/x"><a href="/y">nested</a></a>',
	'<a href="/ok"><script>alert(1)</script>fine</a> <b><script>x</b>',
	'</p></blockquote></ul></li></strong>stray closers<li>stray item',
	$old,
];
$bad = [];
foreach ( $nasty as $n => $raw ) {
	$out    = $F::rich( $raw, [] );
	$pieces = $out['html'];
	foreach ( $out['fun_facts'] as $fact ) {
		$pieces .= $fact['html'];
		if ( $fact['lead'] !== strip_tags( $fact['lead'] ) ) {
			$bad[] = "#$n lead has a tag";
		}
	}
	foreach ( tags_in( $pieces ) as $tag ) {
		if ( ! preg_match( $allowed, $tag ) ) {
			$bad[] = "#$n $tag";
		}
	}
	if ( preg_match( '/\bon[a-z]+\s*=|javascript:|<script|<style|<iframe|<img/i', $pieces ) ) {
		$bad[] = "#$n unsafe text left as markup";
	}
}
check( 'only allowed tags are ever written (14 hostile descriptions)', [] === $bad, implode( '; ', array_slice( $bad, 0, 5 ) ) );
$kses = $F::rich_allowed_html();
ksort( $kses );
check( 'the wp_kses() list is the same short list, href the only attribute',
	[ 'a', 'b', 'blockquote', 'br', 'em', 'i', 'li', 'ol', 'p', 'strong', 'ul' ] === array_keys( $kses )
	&& [ 'href' => true ] === $kses['a'] && [] === array_merge( $kses['p'], $kses['strong'], $kses['blockquote'], $kses['li'] )
);

// ── Beyond the rules → the plain text of prose(), as before v1.30.0 ─────
echo "plain fallback\n";
$beyond = [
	'an attribute with ">" in it'  => 'A <a href="/x" title="a>b">link</a> B',
	'a "<" glued to a word'        => 'I <3 this fish. It is great.',
	'a comment that never closes'  => 'Text <!-- note and more',
	'a broken character'           => "Bad \xC3\x28 bytes",
];
foreach ( $beyond as $label => $raw ) {
	$out   = $F::rich( $raw, [] );
	$prose = $F::prose( $raw, [] );
	$want  = '' === $prose ? '' : '<p>' . nl2br( htmlspecialchars( $prose, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) ) . '</p>';
	check( "$label → plain text, no card", true === $out['plain'] && $want === $out['html'] && [] === $out['fun_facts'] && '' === $out['fun_title'], j( $out ) );
}
$out = $F::rich( "Line one\nRegion: Red Sea\nI <3 it", [ 'region' ] );
check( 'the fallback still hides a filled labelled line', true === $out['plain'] && false === strpos( $out['html'], 'Region' ) && false !== strpos( $out['html'], 'Line one' ), j( $out ) );

// ── Text is never lost ──────────────────────────────────────────────────
echo "nothing typed goes missing\n";
$lost    = [];
$squeeze = function ( $text ) {
	return preg_replace( '/\s+/u', '', html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
};
$samples = [
	"First.\n\nSecond.\n<blockquote>Fun Facts:\n<ul>\n \t<li><strong>Night Owl:</strong> It naps all day.</li>\n \t<li>Sand Sifter: It sifts.</li>\n</ul>\n</blockquote>\nLast.",
	"<h3>Care</h3>Easy. <em>Not</em> nano.\n<ul><li>Feed</li><li>Lid</li></ul>\n<blockquote>Favourite.</blockquote>\nSee <a href=\"/g\">the guide</a>.",
	"Fun Fact: One.\n\nMiddle.\n\nFun Facts: Two.",
	'<table><tr><th>Size</th><td>Small</td></tr></table><hr>After<div>Div text</div>',
	"Fun Facts:\n\nNot shaped like a fact at all, just a long sentence about the fish.",
];
foreach ( $samples as $n => $raw ) {
	$out  = $F::rich( $raw, [] );
	$text = $out['html'];
	foreach ( $out['fun_facts'] as $fact ) {
		$text .= $fact['lead'] . ( '' !== $fact['lead'] ? ':' : '' ) . $fact['html'];
	}
	// Every character typed is in the page or in the card; only the "Fun Facts:" label may go.
	$typed   = count_chars( preg_replace( '/Fun\s*Facts?:/i', '', $squeeze( $F::normalise( $raw ) ), $out['fun_facts'] ? 1 : 0 ), 1 );
	$printed = count_chars( $squeeze( $text ), 1 );
	if ( $typed !== $printed ) {
		$lost[] = "#$n";
	}
}
check( 'every character typed is in the text or in the card', [] === $lost, implode( ', ', $lost ) );

// ── The product page ────────────────────────────────────────────────────
echo "the product page\n";
$pdp = file_get_contents( dirname( __DIR__ ) . '/woocommerce/single-product.php' );
check( 'rich() is called once, behind "not a medication"',
	1 === substr_count( $pdp, 'FisHotel_Product_Facts::rich(' )
	&& (bool) preg_match( '/elseif \( ! \$pdp_is_med_page \) \{\s*\$pdp_rich\s*=\s*FisHotel_Product_Facts::rich\(/', $pdp )
	&& (bool) preg_match( '/\$pdp_is_med_page = \$is_med_or_aff\s*\|\|/', $pdp )
);
check( 'a medication page gets prose(): plain text',
	(bool) preg_match( '/\} else \{\s*\$pdp_desc_plain = FisHotel_Product_Facts::prose\( \$desc_raw, \$facts_shown \);/', $pdp )
);
check( 'the medication test covers both rules, the Medications category and Amazon products',
	false !== strpos( $pdp, "in_array( 'medications', \$_card_cat_slugs, true )" ) && false !== strpos( $pdp, 'FisHotel_Med_Listing::is_med( $product_id )' )
);
check( 'plain text is printed escaped, in one paragraph, as v1.29.0 did',
	false !== strpos( $pdp, '<p><?php echo wp_kses_post( nl2br( esc_html( $pdp_desc_plain ) ) ); ?></p>' )
);
check( 'the rich text is printed through wp_kses() with the short list',
	false !== strpos( $pdp, 'wp_kses( $pdp_desc_html, $pdp_rich_tags )' ) && false !== strpos( $pdp, 'FisHotel_Product_Facts::rich_allowed_html()' )
);
check( 'a fact is printed through wp_kses() and its lead-in through esc_html()',
	false !== strpos( $pdp, 'wp_kses( $pdp_fact[\'html\'], $pdp_fact_tags )' ) && false !== strpos( $pdp, 'esc_html( $pdp_fact[\'lead\'] )' )
);
check( 'no shortcode is run on the description',
	false === strpos( $pdp, 'do_shortcode' ) && false === strpos( $pdp, "apply_filters( 'the_content'" )
);
// The same description on a medication page: what prose() hands over has no markup to print.
$med_desc = "A measured <strong>powder</strong>. See <a href=\"https://example.test/\">the sheet</a>.\n<blockquote>Fun Facts:\n<ul>\n<li>Shelf Life: Keeps two years.</li>\n</ul>\n</blockquote>";
$med_text = $F::prose( $med_desc, [] );
check( 'a medication description comes out of prose() with no tags at all', $med_text === strip_tags( $med_text ) && false !== strpos( $med_text, 'Fun Facts:' ), j( $med_text ) );

echo "\n" . ( $failures ? "$failures FAILED" : 'all passed' ) . "\n";
exit( $failures ? 1 : 0 );
