=== DEV3 AI Course Builder for LearnDash & Tutor LMS ===
Contributors: dev3tech
Tags: lms, tutor lms, learndash, course builder, quiz generator
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn a topic, an outline or a PDF/DOCX into a complete course: sections, lessons, quizzes and certificate text. Review everything, then create it in Tutor LMS, LearnDash or as WordPress pages.

== Description ==

DEV3 AI Course Builder is an add-on for Tutor LMS and LearnDash (and works without any LMS).

* **Sources**: a topic, a pasted outline (Markdown headings, numbered or bulleted lists) or an uploaded PDF, DOCX, TXT or Markdown file.
* **Course tree editor**: sections, lessons, topics and quizzes; add, rename, reorder, delete; lesson content with live preview; question editor for 8 question types.
* **Review first**: nothing is created in your LMS until you click Create course. Every DEV3 AI result is a draft you accept, edit or reject.
* **Background creation** with progress, retry and **undo build**.
* **Tutor LMS**: courses, topics, lessons and quizzes with questions saved in Tutor's own tables.
* **LearnDash**: courses, lessons, topics, quizzes (ProQuiz questions), course builder steps, section headings and a certificate.
* **No LMS**: a parent course page with child lesson pages, self-check quiz pages and a certificate page.
* **Quiz generator** for lessons you already have, and a **lesson rewriter** (simplify, expand, examples, shorten, clarity, tone, reading level, translate) with undo.
* **Course Certificate** block and `[dev3lm_certificate course="ID"]` shortcode: printable certificate for learners who completed the course.
* Works with DEV3 AI switched off: outline import, template outlines, manual editing, basic (heuristic) quiz questions and description/certificate templates.

A Google Gemini API key is needed for the DEV3 AI features. You pay Google for usage; Google offers a free tier. A monthly budget cap is built in.

== Installation ==

1. Upload the plugin zip in Plugins > Add New > Upload and activate it.
2. Optional: activate Tutor LMS or LearnDash.
3. Go to DEV3 AI Courses > API Config and paste your Gemini API key (or skip it to work without AI).
4. Click New course.

== Frequently Asked Questions ==

= Do I need LearnDash or Tutor LMS? =
No. Without an LMS the course is created as WordPress pages.

= Is anything published automatically? =
No. You review the course tree first, and created items use the status you choose (Draft by default).

= Can I undo a course creation? =
Yes. Undo build moves everything the build created to the trash (or deletes it permanently).

= Which data is sent to Google? =
Only the topic/outline/document text (capped in Settings) and the lesson text you ask DEV3 AI to work on.

== Screenshots ==

1. Dashboard
2. DEV3 AI outline draft
3. Course builder
4. Quiz generator (mobile)
5. Course created in Tutor LMS
6. Certificate (mobile)

== Changelog ==

= 1.0.0 =
* Initial release.
