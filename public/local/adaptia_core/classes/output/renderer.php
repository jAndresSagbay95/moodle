<?php
// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_adaptia_core\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Theme-overridable output for ADAPTIA course screens.
 *
 * @package local_adaptia_core
 * @copyright 2026 GIHP4C
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the TutorIA view model through the template API.
     *
     * @param tutoria_page $page View model.
     * @return string
     */
    protected function render_tutoria_page(tutoria_page $page): string {
        return $this->render_from_template('local_adaptia_core/tutoria_page', $page->export_for_template($this));
    }
}
