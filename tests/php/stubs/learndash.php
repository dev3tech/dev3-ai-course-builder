<?php
/**
 * Minimal LearnDash stand-ins (LearnDash is a paid plugin and is not installed in wp-env).
 * They mirror the documented APIs the adapter uses: post types, learndash_update_setting(),
 * learndash_get_course_id(), LDLMS_Factory_Post::course_steps() and the ProQuiz models/mappers.
 *
 * @package Dev3\AiCourseBuilder
 */

// phpcs:ignoreFile

if ( ! defined( 'LEARNDASH_VERSION' ) ) {
	define( 'LEARNDASH_VERSION', '4.20.0-stub' );
}

function dev3lm_test_register_learndash() {
	foreach ( array( 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question', 'sfwd-certificates' ) as $type ) {
		if ( ! post_type_exists( $type ) ) {
			register_post_type( $type, array( 'public' => true, 'label' => $type, 'supports' => array( 'title', 'editor', 'page-attributes' ) ) );
		}
	}
}

if ( ! function_exists( 'learndash_update_setting' ) ) {
	function learndash_update_setting( $post, $key, $value ) {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		$type    = get_post_type( $post_id );
		$meta    = get_post_meta( $post_id, '_' . $type, true );
		$meta    = is_array( $meta ) ? $meta : array();
		$meta[ $type . '_' . $key ] = $value;
		update_post_meta( $post_id, '_' . $type, $meta );
		$GLOBALS['dev3lm_ld_settings'][] = array( $post_id, $key, $value );
		return true;
	}
}

if ( ! function_exists( 'learndash_get_setting' ) ) {
	function learndash_get_setting( $post, $key ) {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		$type    = get_post_type( $post_id );
		$meta    = (array) get_post_meta( $post_id, '_' . $type, true );
		return $meta[ $type . '_' . $key ] ?? '';
	}
}

if ( ! function_exists( 'learndash_get_course_id' ) ) {
	function learndash_get_course_id( $post_id ) {
		return (int) get_post_meta( (int) $post_id, 'course_id', true );
	}
}

if ( ! class_exists( 'Dev3lm_Stub_Model' ) ) {
	/** Generic model: setX()/getX()/isX() store values. */
	class Dev3lm_Stub_Model {
		public $data = array();
		public function __call( $name, $args ) {
			if ( 0 === strpos( $name, 'set' ) ) {
				$this->data[ lcfirst( substr( $name, 3 ) ) ] = $args[0];
				return $this;
			}
			if ( 0 === strpos( $name, 'get' ) ) {
				return $this->data[ lcfirst( substr( $name, 3 ) ) ] ?? null;
			}
			if ( 0 === strpos( $name, 'is' ) ) {
				return ! empty( $this->data[ lcfirst( substr( $name, 2 ) ) ] );
			}
			return null;
		}
	}
	class WpProQuiz_Model_Quiz extends Dev3lm_Stub_Model {}
	class WpProQuiz_Model_Question extends Dev3lm_Stub_Model {}
	class WpProQuiz_Model_AnswerTypes extends Dev3lm_Stub_Model {
		public function setGraded( $v ) { $this->data['graded'] = $v; }
		public function setGradedType( $v ) { $this->data['gradedType'] = $v; }
		public function setGradingProgression( $v ) { $this->data['gradingProgression'] = $v; }
	}
	class WpProQuiz_Model_QuizMapper {
		public static $saved = array();
		public function save( $model ) {
			$model->setId( count( self::$saved ) + 100 );
			self::$saved[ $model->getId() ] = $model;
			return $model;
		}
	}
	class WpProQuiz_Model_QuestionMapper {
		public static $saved = array();
		public function save( $model ) {
			$model->setId( count( self::$saved ) + 500 );
			self::$saved[ $model->getId() ] = $model;
			return $model;
		}
	}
	class Dev3lm_Stub_Course_Steps {
		private $course;
		public function __construct( $course ) { $this->course = $course; }
		public function set_steps( $h ) {
			update_post_meta( $this->course, 'ld_course_steps', array( 'steps' => array( 'h' => $h ), 'course_id' => $this->course ) );
		}
		public function get_steps( $type = 'h' ) {
			$meta = get_post_meta( $this->course, 'ld_course_steps', true );
			return isset( $meta['steps']['h'] ) ? $meta['steps']['h'] : array( 'sfwd-lessons' => array(), 'sfwd-quiz' => array() );
		}
	}
	class LDLMS_Factory_Post {
		public static function course_steps( $course_id ) {
			return new Dev3lm_Stub_Course_Steps( (int) $course_id );
		}
	}
}
