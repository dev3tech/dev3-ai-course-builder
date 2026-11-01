import { __, sprintf } from '@wordpress/i18n';
import { Badge } from '../core3/ui';
import { counts } from './util';

/**
 * Read-only course tree (used for AI outline drafts and source previews).
 *
 * @param {Object} props      Props.
 * @param {Object} props.tree Tree.
 * @param {number} props.max  Max lessons shown per section.
 * @return {Element} Preview.
 */
export default function OutlinePreview( { tree, max = 12 } ) {
	if ( ! tree ) {
		return null;
	}
	const c = counts( tree );
	return (
		<div className="dev3lm-outline">
			<div className="dev3lm-outline__head">
				<p className="dev3lm-outline__title">{ tree.course.title || __( '(untitled course)', 'dev3-ai-course-builder' ) }</p>
				<div className="dev3lm-outline__counts">
					<Badge tone="brand">{ sprintf( /* translators: %d: sections */ __( '%d sections', 'dev3-ai-course-builder' ), c.sections ) }</Badge>
					<Badge tone="brand">{ sprintf( /* translators: %d: lessons */ __( '%d lessons', 'dev3-ai-course-builder' ), c.lessons ) }</Badge>
					{ c.topics > 0 && <Badge>{ sprintf( /* translators: %d: topics */ __( '%d topics', 'dev3-ai-course-builder' ), c.topics ) }</Badge> }
					{ c.quizzes > 0 && <Badge>{ sprintf( /* translators: %d: quizzes */ __( '%d quizzes', 'dev3-ai-course-builder' ), c.quizzes ) }</Badge> }
				</div>
			</div>
			{ tree.course.description && <p className="dev3lm-outline__desc">{ tree.course.description.split( '\n' )[ 0 ] }</p> }
			<ol className="dev3lm-outline__sections">
				{ tree.sections.map( ( s ) => (
					<li key={ s.id }>
						<span className="dev3lm-outline__section">{ s.title || __( '(untitled section)', 'dev3-ai-course-builder' ) }</span>
						<ol className="dev3lm-outline__lessons">
							{ s.lessons.slice( 0, max ).map( ( l ) => (
								<li key={ l.id }>
									{ l.title || __( '(untitled lesson)', 'dev3-ai-course-builder' ) }
									{ l.topics.length > 0 && <span className="dev3lm-muted"> · { sprintf( /* translators: %d: topics */ __( '%d topics', 'dev3-ai-course-builder' ), l.topics.length ) }</span> }
									{ l.content && <span className="dev3lm-muted"> · { __( 'has text', 'dev3-ai-course-builder' ) }</span> }
								</li>
							) ) }
							{ s.lessons.length > max && <li className="dev3lm-muted">{ sprintf( /* translators: %d: more lessons */ __( '…and %d more', 'dev3-ai-course-builder' ), s.lessons.length - max ) }</li> }
						</ol>
						{ s.quiz && <span className="dev3lm-outline__quiz">{ s.quiz.title || __( 'Quiz', 'dev3-ai-course-builder' ) }</span> }
					</li>
				) ) }
			</ol>
			{ tree.quiz && <p className="dev3lm-outline__quiz">{ tree.quiz.title }</p> }
		</div>
	);
}
