<?php
/**
 * Tree, Markdown-lite, outline parser, extractors, heuristic quiz.
 *
 * @package Dev3\AiCourseBuilder
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiCourseBuilder\Course\Basic_Quiz;
use Dev3\AiCourseBuilder\Course\Markdown;
use Dev3\AiCourseBuilder\Course\Tree;
use Dev3\AiCourseBuilder\Source\Extractor;
use Dev3\AiCourseBuilder\Source\Outline_Parser;

class CourseModelTest extends Dev3lm_Base_Case {

	public function test_normalize_adds_ids_fixes_duplicates_and_clamps() {
		$tree = Tree::normalize(
			array(
				'course'   => array(
					'title' => '<b>Coffee</b>',
					'level' => 'wizard',
				),
				'sections' => array(
					array(
						'id'      => 'dup',
						'title'   => 'A',
						'lessons' => array(
							array(
								'id'    => 'dup',
								'title' => 'L1',
							),
						),
					),
					'garbage',
				),
			)
		);
		$this->assertSame( 'Coffee', $tree['course']['title'] );
		$this->assertSame( 'all_levels', $tree['course']['level'] );
		$this->assertCount( 1, $tree['sections'] );
		$this->assertSame( 'dup', $tree['sections'][0]['id'] );
		$this->assertNotSame( 'dup', $tree['sections'][0]['lessons'][0]['id'] );
		$this->assertMatchesRegularExpression( '/^l[a-z0-9]{8}$/', $tree['sections'][0]['lessons'][0]['id'] );
		$this->assertNull( $tree['quiz'] );
	}

	public function test_questions_are_repaired_per_type() {
		$single = Tree::question(
			array(
				'type'    => 'single',
				'text'    => 'Q',
				'answers' => array(
					array(
						'text'    => 'A',
						'correct' => true,
					),
					array(
						'text'    => 'B',
						'correct' => true,
					),
				),
			)
		);
		$this->assertSame( array( true, false ), wp_list_pluck( $single['answers'], 'correct' ) );
		$tf = Tree::question(
			array(
				'type'    => 'true_false',
				'text'    => 'Sky is green',
				'answers' => array(
					array(
						'text'    => 'False',
						'correct' => true,
					),
				),
			)
		);
		$this->assertSame( array( 'True', 'False' ), wp_list_pluck( $tf['answers'], 'text' ) );
		$this->assertSame( array( false, true ), wp_list_pluck( $tf['answers'], 'correct' ) );
		$blank = Tree::question(
			array(
				'type'    => 'fill_blank',
				'text'    => 'Water boils at ___ degrees',
				'answers' => array( '100' ),
			)
		);
		$this->assertSame( 'Water boils at {blank} degrees', $blank['text'] );
		$this->assertSame( '100', $blank['answers'][0]['text'] );
		$this->assertNull( Tree::question( array( 'type' => 'single' ) ) );
		$essay = Tree::question(
			array(
				'type'    => 'essay',
				'text'    => 'Explain',
				'answers' => array( 'x' ),
			)
		);
		$this->assertSame( array(), $essay['answers'] );
	}

	public function test_issues_counts_index_and_update() {
		$tree   = Tree::normalize( $this->sample_tree() );
		$counts = Tree::counts( $tree );
		$this->assertSame( 2, $counts['sections'] );
		$this->assertSame( 3, $counts['lessons'] );
		$this->assertSame( 1, $counts['topics'] );
		$this->assertSame( 3, $counts['quizzes'] );
		$this->assertSame( 6, $counts['questions'] );
		$errors = array_filter( Tree::issues( $tree ), static fn( $i ) => 'error' === $i['level'] );
		$this->assertSame( array(), array_values( $errors ) );
		$index = Tree::index( $tree );
		$this->assertSame( 'topic', $index['tlabel']['kind'] );
		$this->assertSame( 'lbeans', $index['tlabel']['lesson'] );
		$this->assertSame( 'sbasics', $index['qbasics']['section'] );
		$this->assertNull( $index['qbasics']['lesson'] );
		$updated = Tree::update_node(
			$tree,
			'lpour',
			static function ( $n ) {
				$n['title'] = 'Pour over';
				return $n;
			}
		);
		$this->assertSame( 'Pour over', $updated['sections'][1]['lessons'][0]['title'] );
		$bad = $tree;
		$bad['course']['title'] = '';
		$this->assertSame( 'course', Tree::issues( Tree::normalize( $bad ) )[0]['node'] );
		$placed = Tree::place_quizzes( Tree::normalize( array( 'sections' => array( array( 'title' => 'S', 'lessons' => array( array( 'title' => 'L' ) ) ) ) ) ), 'lesson' );
		$this->assertNotNull( $placed['sections'][0]['lessons'][0]['quiz'] );
	}

	public function test_markdown_to_native_blocks_escapes_everything() {
		$html = Markdown::to_blocks( "## Title & more\n\nHello **bold** *it* `x<y` <script>alert(1)</script> [link](https://example.com)\n\n- one\n- two\n\n1. first\n\n> Tip: careful\n\n> plain quote\n\n```\nif (a < b) {}\n```\n\n---" );
		$this->assertStringContainsString( '<!-- wp:heading -->', $html );
		$this->assertStringContainsString( 'Title &amp; more', $html );
		$this->assertStringContainsString( '<strong>bold</strong>', $html );
		$this->assertStringContainsString( '<em>it</em>', $html );
		$this->assertStringContainsString( '<code>x&lt;y</code>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '<a href="https://example.com">link</a>', $html );
		$this->assertStringContainsString( '<!-- wp:list -->', $html );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $html );
		$this->assertStringContainsString( 'dev3lm-callout', $html );
		$this->assertStringContainsString( '<!-- wp:quote -->', $html );
		$this->assertStringContainsString( 'if (a &lt; b) {}', $html );
		$this->assertStringContainsString( '<!-- wp:separator -->', $html );
		// Every block parses as a known core block.
		$names = array_filter( wp_list_pluck( parse_blocks( $html ), 'blockName' ) );
		$this->assertSame( array( 'core/heading', 'core/paragraph', 'core/list', 'core/list', 'core/paragraph', 'core/quote', 'core/code', 'core/separator' ), array_values( $names ) );
		$this->assertStringNotContainsString( 'javascript:', Markdown::to_blocks( '[x](javascript:alert(1))' ) );
	}

	public function test_markdown_round_trip_and_ai_blocks() {
		$md   = "## Freshness\n\nBuy **fresh** beans.\n\n- Roast date\n- Storage\n\n> Tip: use a jar";
		$back = Markdown::from_html( Markdown::to_blocks( $md ) );
		$this->assertSame( $md, $back );
		$ai = Markdown::from_ai_blocks(
			array(
				array(
					'type' => 'heading',
					'text' => 'Intro',
				),
				array(
					'type'  => 'ordered_list',
					'items' => array( '1. Boil', 'Pour' ),
				),
				array(
					'type' => 'callout',
					'text' => 'Do not burn it',
				),
				array(
					'type' => 'code',
					'text' => 'brew --strong',
				),
			)
		);
		$this->assertSame( "## Intro\n\n1. Boil\n2. Pour\n\n> Tip: Do not burn it\n\n```\nbrew --strong\n```", $ai );
		$this->assertStringContainsString( '# Old', Markdown::from_html( '<h1>Old</h1><p>Text<br>more</p><ul><li><b>a</b></li></ul>' ) );
	}

	public function test_outline_parser_markdown_headings_quiz_and_content() {
		$tree = Outline_Parser::parse( "# Home Coffee\n\nIntro text.\n\n## Module 1: Beans\n### Choosing beans\nPick fresh beans.\nMore text here.\n\n- roast date\n- origin\n### Grinding\n## Module 2: Brewing\n### Pour-over\n#### Bloom\nWait 30s.\n## Quiz\nWhat grind suits a French press?\n- [x] Coarse\n- [ ] Fine\nWhy bloom?" );
		$this->assertSame( 'Home Coffee', $tree['course']['title'] );
		$this->assertSame( 'Intro text.', $tree['course']['description'] );
		$this->assertCount( 2, $tree['sections'] );
		$this->assertSame( 'Beans', $tree['sections'][0]['title'] );
		$this->assertSame( array( 'Choosing beans', 'Grinding' ), wp_list_pluck( $tree['sections'][0]['lessons'], 'title' ) );
		$this->assertSame( "Pick fresh beans. More text here.\n\n- roast date\n- origin", $tree['sections'][0]['lessons'][0]['content'] );
		$this->assertSame( 'Bloom', $tree['sections'][1]['lessons'][0]['topics'][0]['title'] );
		$this->assertSame( 'Wait 30s.', $tree['sections'][1]['lessons'][0]['topics'][0]['content'] );
		$quiz = $tree['sections'][1]['quiz'];
		$this->assertNotNull( $quiz );
		$this->assertCount( 2, $quiz['questions'] );
		$this->assertSame( 'single', $quiz['questions'][0]['type'] );
		$this->assertTrue( $quiz['questions'][0]['answers'][0]['correct'] );
		$this->assertSame( 'short_answer', $quiz['questions'][1]['type'] );
	}

	public function test_outline_parser_keywords_numbers_and_bullets() {
		$kw = Outline_Parser::parse( "Module 1: Basics\nLesson 1: Hello\nLesson 2: World\nModule 2: More\nLesson 1: Again", 'Course' );
		$this->assertSame( array( 'Basics', 'More' ), wp_list_pluck( $kw['sections'], 'title' ) );
		$this->assertCount( 2, $kw['sections'][0]['lessons'] );
		$num = Outline_Parser::parse( "1.1 Setup\n1.2 Config\n2.1 Deploy", 'Ops' );
		$this->assertSame( 'Ops', $num['course']['title'] );
		$this->assertSame( 3, Tree::counts( $num )['lessons'] );
		$bul = Outline_Parser::parse( "- Basics\n  - Hello\n    - Detail\n  - World\n- Advanced\n  - Scale", 'B' );
		$this->assertSame( array( 'Basics', 'Advanced' ), wp_list_pluck( $bul['sections'], 'title' ) );
		$this->assertSame( 'Detail', $bul['sections'][0]['lessons'][0]['topics'][0]['title'] );
		$flat = Outline_Parser::parse( "- One\n- Two\n- Three", 'Flat' );
		$this->assertCount( 1, $flat['sections'] );
		$this->assertCount( 3, $flat['sections'][0]['lessons'] );
		$tpl = Outline_Parser::template( 'Excel', 3, 2 );
		$this->assertSame( 'Excel', $tpl['course']['title'] );
		$this->assertSame( 6, Tree::counts( $tpl )['lessons'] );
	}

	public function test_docx_extraction_keeps_headings_and_lists() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive missing' );
		}
		$xml  = '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'
			. '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>Excel Basics</w:t></w:r></w:p>'
			. '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Formulas</w:t></w:r></w:p>'
			. '<w:p><w:pPr><w:pStyle w:val="Heading3"/></w:pPr><w:r><w:t>SUM &amp; AVERAGE</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t xml:space="preserve">Add numbers </w:t></w:r><w:r><w:t>quickly.</w:t></w:r></w:p>'
			. '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/></w:numPr></w:pPr><w:r><w:t>Select cells</w:t></w:r></w:p>'
			. '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/></w:numPr></w:pPr><w:r><w:t>Press Enter</w:t></w:r></w:p>'
			. '</w:body></w:document>';
		$path = wp_tempnam( 'dev3lm.docx' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'word/document.xml', $xml );
		$zip->close();
		$md = Extractor::text( $path, 'docx' );
		$this->assertSame( "# Excel Basics\n\n## Formulas\n\n### SUM & AVERAGE\n\nAdd numbers quickly.\n\n- Select cells\n- Press Enter", $md );
		$tree = Outline_Parser::parse( $md );
		$this->assertSame( 'Excel Basics', $tree['course']['title'] );
		$this->assertSame( 'SUM & AVERAGE', $tree['sections'][0]['lessons'][0]['title'] );
		wp_delete_file( $path );
		$this->assertWPError( Extractor::docx( __FILE__ ) );
	}

	public function test_pdf_text_extraction_flate_and_plain() {
		$content = "BT /F1 12 Tf 72 720 Td (Module 1: Basics) Tj 0 -20 Td (Lesson 1: Hello \\(world\\)) Tj 0 -20 Td [(Spa) -300 (ced)] TJ ET";
		$pdf     = "%PDF-1.4\n1 0 obj << /Length " . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream\nendobj\n"
			. '2 0 obj << /Filter /FlateDecode /Length 10 >>' . "\nstream\n" . gzcompress( 'BT (Compressed line) Tj ET' ) . "\nendstream\nendobj\n%%EOF";
		$text    = Extractor::pdf( $pdf );
		$this->assertSame( "Module 1: Basics\nLesson 1: Hello (world)\nSpa ced\nCompressed line", $text );
		$this->assertSame( '', Extractor::pdf( 'not a pdf' ) );
		$this->assertSame( 'pdf', Extractor::kind( 'application/pdf', 'x' ) );
		$this->assertSame( 'txt', Extractor::kind( 'application/octet-stream', 'notes.md' ) );
		$this->assertSame( '', Extractor::kind( 'image/png', 'x.png' ) );
	}

	public function test_attachment_validation() {
		$this->assertWPError( Extractor::attachment( 0 ) );
		$upload = wp_upload_bits( 'outline.txt', null, "# A\n## B\n### C" );
		$id     = self::factory()->attachment->create_object(
			$upload['file'],
			0,
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'outline',
			)
		);
		$file = Extractor::attachment( $id );
		$this->assertSame( 'txt', $file['kind'] );
		$png = self::factory()->attachment->create_object( $upload['file'], 0, array( 'post_mime_type' => 'image/png' ) );
		$this->assertSame( 'dev3lm_file_type', Extractor::attachment( $png )->get_error_code() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'dev3lm_forbidden', Extractor::attachment( $id )->get_error_code() );
	}

	public function test_basic_quiz_from_lesson_text() {
		$md        = "## Espresso\n\nEspresso is a concentrated coffee brewed under pressure.\n\nA tamper compresses the coffee grounds evenly in the basket.\n\n## Milk\n\nMicrofoam gives latte art its glossy texture.";
		$questions = Basic_Quiz::from_text( $md, 4, array( 'fill_blank', 'true_false', 'short_answer' ) );
		$this->assertCount( 4, $questions );
		$this->assertSame( 'fill_blank', $questions[0]['type'] );
		$this->assertStringContainsString( '{blank}', $questions[0]['text'] );
		$this->assertSame( 'Espresso', $questions[0]['answers'][0]['text'] );
		$types = wp_list_pluck( $questions, 'type' );
		$this->assertContains( 'fill_blank', $types );
		$tf = Basic_Quiz::from_text( $md, 2, array( 'true_false' ) );
		$this->assertSame( array( 'true_false', 'true_false' ), wp_list_pluck( $tf, 'type' ) );
		$this->assertSame( array(), Basic_Quiz::from_text( 'Too short.', 3, array( 'fill_blank' ) ) );
		$open = Basic_Quiz::from_text( $md, 5, array( 'essay' ) );
		$this->assertStringContainsString( 'Espresso', $open[0]['text'] );
	}
}
