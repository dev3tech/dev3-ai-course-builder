<?php
/**
 * Course blueprints: create from a source, edit the tree, DEV3 AI drafts, build and undo.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Rest;

use Dev3\AiCourseBuilder\Ai\Generator;
use Dev3\AiCourseBuilder\Core3\Api\Ai;
use Dev3\AiCourseBuilder\Core3\Core\Jobs;
use Dev3\AiCourseBuilder\Core3\Rest\Base_Controller;
use Dev3\AiCourseBuilder\Course\Basic_Quiz;
use Dev3\AiCourseBuilder\Course\Markdown;
use Dev3\AiCourseBuilder\Course\Tree;
use Dev3\AiCourseBuilder\Data\Blueprints;
use Dev3\AiCourseBuilder\Data\Rewrites;
use Dev3\AiCourseBuilder\Lms\Adapters;
use Dev3\AiCourseBuilder\Service\Builder;
use Dev3\AiCourseBuilder\Service\Content_Job;
use Dev3\AiCourseBuilder\Settings;
use Dev3\AiCourseBuilder\Source\Extractor;
use Dev3\AiCourseBuilder\Source\Outline_Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3lm/v1.
 */
class Blueprint_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$id     = '/blueprints/(?P<id>\d+)';
		$routes = array(
			'/status'          => array( array( 'GET', 'status' ) ),
			'/blueprints'      => array( array( 'GET', 'index' ), array( 'POST', 'create' ) ),
			$id                => array( array( 'GET', 'read' ), array( 'PUT', 'save' ), array( 'DELETE', 'remove' ) ),
			$id . '/duplicate' => array( array( 'POST', 'duplicate' ) ),
			$id . '/outline'   => array( array( 'POST', 'outline' ) ),
			$id . '/lesson'    => array( array( 'POST', 'lesson' ) ),
			$id . '/quiz'      => array( array( 'POST', 'quiz' ) ),
			$id . '/details'   => array( array( 'POST', 'details' ) ),
			$id . '/draft-all' => array( array( 'POST', 'draft_all' ) ),
			$id . '/build'     => array( array( 'POST', 'build' ) ),
			$id . '/unbuild'   => array( array( 'POST', 'unbuild' ) ),
			'/source/parse'    => array( array( 'POST', 'parse' ) ),
		);
		foreach ( $routes as $path => $handlers ) {
			$args = array();
			foreach ( $handlers as $h ) {
				$args[] = array(
					'methods'             => $h[0],
					'callback'            => array( $this, $h[1] ),
					'permission_callback' => $manage,
				);
			}
			register_rest_route( self::NS, $path, $args );
		}
	}

	/**
	 * GET status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$current = Adapters::resolve( 'auto' );
		$built   = 0;
		$lessons = 0;
		foreach ( Blueprints::query( array( 'per_page' => 100 ) )['items'] as $bp ) {
			if ( 'built' === $bp['status'] ) {
				++$built;
				$lessons += (int) $bp['counts']['lessons'];
			}
		}
		return $this->respond(
			array(
				'adapters' => Adapters::describe(),
				'auto'     => is_wp_error( $current ) ? 'pages' : $current->id(),
				'default'  => (string) Settings::get( 'default_lms', 'auto' ),
				'counts'   => Blueprints::counts(),
				'built'    => $built,
				'lessons'  => $lessons,
				'rewrites' => Rewrites::count(),
				'ai'       => Ai::status(),
				'recent'   => Blueprints::query( array( 'per_page' => 5 ) )['items'],
			)
		);
	}

	/**
	 * GET blueprints.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $r ) {
		return $this->respond(
			Blueprints::query(
				array(
					'status'   => sanitize_key( (string) $r['status'] ),
					'search'   => sanitize_text_field( (string) $r['search'] ),
					'page'     => (int) $r['page'],
					'per_page' => (int) ( $r['per_page'] ? $r['per_page'] : 20 ),
				)
			)
		);
	}

	/**
	 * POST blueprints { mode: topic|outline|file|import|blank, title, topic, outline, attachment_id, tree, options, template }.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $r ) {
		$mode   = sanitize_key( (string) $r['mode'] );
		$opts   = Settings::options( (array) ( $r['options'] ? $r['options'] : array() ) );
		$title  = Tree::line( $r['title'], 200 );
		$topic  = Tree::text( $r['topic'], 2000 );
		$source = array(
			'topic'   => $topic,
			'options' => $opts,
		);
		$max    = (int) Settings::get( 'max_source_chars', 30000 );
		$tree   = null;
		switch ( $mode ) {
			case 'outline':
				$outline = mb_substr( str_replace( "\r", '', (string) $r['outline'] ), 0, $max );
				if ( '' === trim( $outline ) ) {
					return $this->respond( new \WP_Error( 'dev3lm_empty', __( 'Paste an outline first.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) ) );
				}
				$source['outline'] = $outline;
				$tree              = Outline_Parser::parse( $outline, '' !== $title ? $title : $topic );
				break;
			case 'file':
				$file = Extractor::attachment( (int) $r['attachment_id'] );
				if ( is_wp_error( $file ) ) {
					return $this->respond( $file );
				}
				$text = Extractor::text( $file['path'], $file['kind'] );
				if ( is_wp_error( $text ) ) {
					return $this->respond( $text );
				}
				$source['attachment_id'] = (int) $r['attachment_id'];
				$source['file_name']     = $file['name'];
				$source['file_kind']     = $file['kind'];
				$source['text']          = mb_substr( $text, 0, $max );
				$fallback                = '' !== $title ? $title : (string) preg_replace( '/\.[a-z0-9]+$/i', '', str_replace( array( '-', '_' ), ' ', $file['name'] ) );
				$tree                    = '' !== trim( $text ) ? Outline_Parser::parse( $source['text'], $fallback ) : Tree::blank( $fallback );
				break;
			case 'import':
				$tree = Tree::normalize( (array) $r['tree'] );
				if ( '' === $tree['course']['title'] ) {
					return $this->respond( new \WP_Error( 'dev3lm_import', __( 'That file is not a DEV3 course export.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) ) );
				}
				$mode = 'import';
				break;
			case 'blank':
				$tree = Tree::blank( '' !== $title ? $title : __( 'Untitled course', 'dev3-ai-course-builder' ) );
				break;
			default:
				$mode = 'topic';
				if ( '' === $topic && '' === $title ) {
					return $this->respond( new \WP_Error( 'dev3lm_empty', __( 'Describe the course topic first.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) ) );
				}
				$name = '' !== $title ? $title : mb_substr( (string) strtok( $topic, "\n" ), 0, 120 );
				$tree = $r['template'] ? Outline_Parser::template( $name, (int) $opts['sections'], (int) $opts['lessons_per_section'] ) : Tree::blank( $name );
		}
		if ( ! in_array( $mode, array( 'import', 'blank' ), true ) ) {
			$tree = Tree::place_quizzes( $tree, (string) $opts['quiz_placement'] );
		}
		if ( '' === $tree['course']['audience'] && '' !== $opts['audience'] ) {
			$tree['course']['audience'] = $opts['audience'];
		}
		if ( 'all_levels' === $tree['course']['level'] && 'import' !== $mode ) {
			$tree['course']['level'] = $opts['level'];
		}
		$id = Blueprints::create(
			array(
				'title'       => $tree['course']['title'],
				'source_type' => $mode,
				'source'      => $source,
				'tree'        => $tree,
				'lms'         => sanitize_key( (string) $r['lms'] ),
			)
		);
		return $this->respond( is_wp_error( $id ) ? $id : $this->full( $id ), 201 );
	}

	/**
	 * Blueprint + issues.
	 *
	 * @param int $id Id.
	 * @return array|\WP_Error
	 */
	private function full( $id ) {
		$bp = Blueprints::get( $id );
		if ( ! $bp ) {
			return new \WP_Error( 'dev3lm_not_found', __( 'Course draft not found.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) );
		}
		$bp['issues'] = Tree::issues( $bp['tree'] );
		if ( isset( $bp['source']['text'] ) ) {
			$bp['source']['text_length'] = mb_strlen( (string) $bp['source']['text'] );
			$bp['source']['text']        = mb_substr( (string) $bp['source']['text'], 0, 4000 );
		}
		if ( $bp['job_id'] ) {
			$bp['job'] = Jobs::get( $bp['job_id'] );
		}
		$content_job = null;
		foreach ( Jobs::recent( Content_Job::JOB, 10 ) as $job ) {
			if ( (int) ( $job['args']['blueprint'] ?? 0 ) === (int) $id ) {
				$content_job = $job;
				break;
			}
		}
		$bp['content_job'] = $content_job;
		if ( ! empty( $bp['result']['course_id'] ) ) {
			$adapter = Adapters::get( (string) ( $bp['result']['lms'] ?? $bp['lms'] ) );
			if ( $adapter && get_post( (int) $bp['result']['course_id'] ) ) {
				$bp['result']['links']  = $adapter->links( (int) $bp['result']['course_id'] );
				$bp['result']['exists'] = 'trash' !== get_post_status( (int) $bp['result']['course_id'] );
			}
		}
		return $bp;
	}

	/**
	 * Loads or 404s.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array|\WP_Error
	 */
	private function load( \WP_REST_Request $r ) {
		$bp = Blueprints::get( (int) $r['id'] );
		return $bp ? $bp : new \WP_Error( 'dev3lm_not_found', __( 'Course draft not found.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) );
	}

	/**
	 * GET blueprints/<id>.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function read( \WP_REST_Request $r ) {
		return $this->respond( $this->full( (int) $r['id'] ) );
	}

	/**
	 * PUT blueprints/<id> { tree, lms }.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function save( \WP_REST_Request $r ) {
		$bp = $this->load( $r );
		if ( is_wp_error( $bp ) ) {
			return $this->respond( $bp );
		}
		if ( 'building' === $bp['status'] ) {
			return $this->respond( new \WP_Error( 'dev3lm_busy', __( 'The course is being created right now. Wait until it finishes.', 'dev3-ai-course-builder' ), array( 'status' => 409 ) ) );
		}
		$data = array();
		if ( is_array( $r['tree'] ) ) {
			$data['tree'] = $r['tree'];
		}
		if ( null !== $r['lms'] ) {
			$data['lms'] = sanitize_key( (string) $r['lms'] );
		}
		Blueprints::update( $bp['id'], $data );
		return $this->respond( $this->full( $bp['id'] ) );
	}

	/**
	 * DELETE blueprints/<id> (created LMS content stays).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function remove( \WP_REST_Request $r ) {
		$bp = $this->load( $r );
		if ( is_wp_error( $bp ) ) {
			return $this->respond( $bp );
		}
		Blueprints::delete( $bp['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST duplicate.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function duplicate( \WP_REST_Request $r ) {
		$bp = $this->load( $r );
		if ( is_wp_error( $bp ) ) {
			return $this->respond( $bp );
		}
		$tree = $bp['tree'];
		/* translators: %s: course title */
		$tree['course']['title'] = mb_substr( sprintf( __( '%s (copy)', 'dev3-ai-course-builder' ), $tree['course']['title'] ), 0, 200 );
		$id                      = Blueprints::create(
			array(
				'title'       => $tree['course']['title'],
				'source_type' => $bp['source_type'],
				'source'      => $bp['source'],
				'tree'        => $tree,
				'lms'         => $bp['lms'],
			)
		);
		return $this->respond( is_wp_error( $id ) ? $id : $this->full( $id ), 201 );
	}

	/**
	 * Source for AI calls (stored text + file).
	 *
	 * @param array $bp Blueprint.
	 * @return array
	 */
	private function source( array $bp ) {
		$src = $bp['source'];
		if ( ! empty( $src['attachment_id'] ) ) {
			$file = Extractor::attachment( (int) $src['attachment_id'] );
			if ( ! is_wp_error( $file ) ) {
				$src['file'] = $file;
			}
		}
		return $src;
	}

	/**
	 * POST outline: AI outline draft (the client shows it and saves it only when accepted).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function outline( \WP_REST_Request $r ) {
		$bp = $this->load( $r );
		if ( is_wp_error( $bp ) ) {
			return $this->respond( $bp );
		}
		$opts = Settings::options( array_merge( (array) ( $bp['source']['options'] ?? array() ), (array) ( $r['options'] ? $r['options'] : array() ) ) );
		$src  = $this->source( $bp );
		if ( empty( $src['topic'] ) && empty( $src['outline'] ) && empty( $src['text'] ) && empty( $src['file'] ) ) {
			$src['topic'] = $bp['tree']['course']['title'];
		}
		if ( '' !== trim( (string) $r['topic'] ) ) {
			$src['topic'] = Tree::text( $r['topic'], 2000 );
		}
		$draft = Generator::outline( $src, $opts );
		if ( ! is_wp_error( $draft ) ) {
			$draft['counts'] = Tree::counts( $draft['tree'] );
		}
		return $this->respond( $draft );
	}

	/**
	 * POST lesson { node }: AI lesson content draft.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function lesson( \WP_REST_Request $r ) {
		$bp = $this->load( $r );
		if ( is_wp_error( $bp ) ) {
			return $this->respond( $bp );
		}
		$tree = is_array( $r['tree'] ) ? Tree::normalize( $r['tree'] ) : $bp['tree'];
		$opts = Settings::options( (array) ( $bp['source']['options'] ?? array() ) );
		if ( $r['length'] ) {
			$opts['lesson_length'] = in_array( $r['length'], Settings::LENGTHS, true ) ? $r['length'] : $opts['lesson_length'];
		}
		return $this->respond( Generator::lesson( $tree, sanitize_key( (string) $r['node'] ), (string) ( $bp['source']['text'] ?? '' ), $opts ) );
	}

	/**
	 * POST quiz { node, count, types, mode: ai|basic }: questions draft for a quiz node.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function quiz( \WP_REST_Request $r ) {
		$bp = $this->load( $r );
		if ( is_wp_error( $bp ) ) {
			return $this->respond( $bp );
		}
		$tree  = is_array( $r['tree'] ) ? Tree::normalize( $r['tree'] ) : $bp['tree'];
		$index = Tree::index( $tree );
		$node  = sanitize_key( (string) $r['node'] );
		if ( ! isset( $index[ $node ] ) || 'quiz' !== $index[ $node ]['kind'] ) {
			return $this->respond( new \WP_Error( 'dev3lm_node', __( 'That quiz no longer exists in this course.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) ) );
		}
		$entry    = $index[ $node ];
		$material = self::material( $tree, $entry );
		$opts     = Settings::options( (array) ( $bp['source']['options'] ?? array() ) );
		$types    = is_array( $r['types'] ) ? array_values( array_intersect( Settings::QUESTION_TYPES, array_map( 'strval', $r['types'] ) ) ) : (array) $opts['question_types'];
		$adapter  = Adapters::get( $bp['lms'] ? $bp['lms'] : 'pages' );
		if ( $adapter ) {
			$types = array_values( array_intersect( $types, $adapter->supports()['types'] ) );
		}
		$count = $r['count'] ? (int) $r['count'] : (int) $opts['questions_per_quiz'];
		if ( 'basic' === $r['mode'] ) {
			$questions = Basic_Quiz::from_text( $material, $count, $types ? $types : array( 'fill_blank', 'true_false', 'short_answer' ) );
			if ( ! $questions ) {
				return $this->respond( new \WP_Error( 'dev3lm_empty', __( 'Not enough lesson text for basic questions. Write the lessons first or add questions by hand.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) ) );
			}
			return $this->respond(
				array(
					'questions' => $questions,
					'model'     => '',
					'tokens'    => 0,
					'cached'    => false,
					'basic'     => true,
				)
			);
		}
		return $this->respond(
			Generator::quiz(
				$material,
				array(
					'count'    => $count,
					'types'    => $types,
					'title'    => $entry['node']['title'],
					'course'   => $tree['course']['title'],
					'level'    => $tree['course']['level'],
					'language' => $opts['language'],
					'tone'     => $opts['tone'],
				)
			)
		);
	}

	/**
	 * Material a quiz is about (its lesson, its section's lessons, or the whole course).
	 *
	 * @param array $tree  Tree.
	 * @param array $entry Index entry of the quiz.
	 * @return string
	 */
	public static function material( array $tree, array $entry ) {
		$parts = array();
		$add   = static function ( $l ) use ( &$parts ) {
			$parts[] = '## ' . $l['title'] . "\n\n" . ( '' !== trim( $l['content'] ) ? $l['content'] : $l['summary'] );
			foreach ( $l['topics'] as $t ) {
				$parts[] = '### ' . $t['title'] . "\n\n" . ( '' !== trim( $t['content'] ) ? $t['content'] : $t['summary'] );
			}
		};
		foreach ( $tree['sections'] as $s ) {
			if ( $entry['section'] && $entry['section'] !== $s['id'] ) {
				continue;
			}
			foreach ( $s['lessons'] as $l ) {
				if ( $entry['lesson'] && $entry['lesson'] !== $l['id'] ) {
					continue;
				}
				$add( $l );
			}
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * POST details { mode }: description + certificate draft.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function details( \WP_REST_Request $r ) {
		$bp = $this->load( $r );
		if ( is_wp_error( $bp ) ) {
			return $this->respond( $bp );
		}
		$tree = is_array( $r['tree'] ) ? Tree::normalize( $r['tree'] ) : $bp['tree'];
		if ( 'basic' === $r['mode'] ) {
			return $this->respond(
				array(
					'draft'  => Generator::details_basic( $tree ),
					'model'  => '',
					'tokens' => 0,
					'cached' => false,
					'basic'  => true,
				)
			);
		}
		return $this->respond( Generator::details( $tree, Settings::options( (array) ( $bp['source']['options'] ?? array() ) ) ) );
	}

	/**
	 * POST draft-all { nodes? }: background job that writes every empty lesson.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function draft_all( \WP_REST_Request $r ) {
		$nodes = is_array( $r['nodes'] ) ? array_map( 'sanitize_key', $r['nodes'] ) : array();
		$job   = Content_Job::start( (int) $r['id'], $nodes );
		return $this->respond( $job, is_wp_error( $job ) ? 200 : 201 );
	}

	/**
	 * POST build { lms, status }.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function build( \WP_REST_Request $r ) {
		$job = Builder::start( (int) $r['id'], sanitize_key( (string) $r['lms'] ), sanitize_key( (string) $r['status'] ) );
		return $this->respond( $job, is_wp_error( $job ) ? 200 : 201 );
	}

	/**
	 * POST unbuild { force }.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function unbuild( \WP_REST_Request $r ) {
		$result = Builder::unbuild( (int) $r['id'], (bool) $r['force'] );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		return $this->respond( array_merge( $result, array( 'blueprint' => $this->full( (int) $r['id'] ) ) ) );
	}

	/**
	 * POST source/parse { text | attachment_id }: non-AI preview of how a source becomes a tree.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function parse( \WP_REST_Request $r ) {
		$text = (string) $r['text'];
		$name = '';
		if ( $r['attachment_id'] ) {
			$file = Extractor::attachment( (int) $r['attachment_id'] );
			if ( is_wp_error( $file ) ) {
				return $this->respond( $file );
			}
			$text = Extractor::text( $file['path'], $file['kind'] );
			if ( is_wp_error( $text ) ) {
				return $this->respond( $text );
			}
			$name = $file['name'];
		}
		$text = mb_substr( str_replace( "\r", '', $text ), 0, (int) Settings::get( 'max_source_chars', 30000 ) );
		$tree = Outline_Parser::parse( $text, (string) preg_replace( '/\.[a-z0-9]+$/i', '', $name ) );
		return $this->respond(
			array(
				'text'    => mb_substr( $text, 0, 4000 ),
				'length'  => mb_strlen( $text ),
				'words'   => str_word_count( Markdown::to_text( $text ) ),
				'tree'    => $tree,
				'counts'  => Tree::counts( $tree ),
				'scanned' => '' === trim( $text ),
			)
		);
	}
}
