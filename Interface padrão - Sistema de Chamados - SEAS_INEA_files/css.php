/*!
 * -------------------------------------------------------------------------
 * gantt plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of gantt.
 *
 * gantt is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * any later version.
 *
 * gantt is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with gantt. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2013-2023 by gantt plugin team.
 * @license   GPLv2 https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/pluginsGLPI/gantt
 * -------------------------------------------------------------------------
 */
@keyframes spin {
  100% {
    transform: rotate(360deg);
  }
}
.gantt-block__features {
  height: 55px;
  border-bottom-color: rgb(62, 67, 70);
  border-left-color: rgb(62, 67, 70);
  border-right-color: rgb(62, 67, 70);
  opacity: 0.85;
  z-index: 1000;
}
.gantt-block__features fieldset {
  border: solid 1px #655;
  border-radius: 5px;
}
.gantt-block__features fieldset #search {
  width: 150px;
  height: 22px;
  font-size: 14px;
}
.gantt-block__controls {
  font-size: 14px !important;
  list-style-type: none;
  margin: 0;
  overflow: visible;
  padding: 0;
  text-align: left;
  user-select: none;
}
.gantt-block__controls .gantt-menu-item {
  display: inline-block;
  box-sizing: border-box;
  position: relative;
  overflow: visible;
  padding: 0 5px;
}
.gantt-block__controls .gantt-menu-item img {
  vertical-align: middle;
}
.gantt-block__controls .gantt-menu-item-right {
  float: right;
}
.gantt-block__controls .fas.fa-expand,
.gantt-block__controls .fa-solid.fa-expand {
  margin-right: 5px;
}
.gantt-block .highlight {
  background-color: #8ec547 !important;
}
.gantt-block .gantt_task_progress {
  text-align: left;
  padding-left: 5px;
  box-sizing: border-box;
  color: rgba(255, 255, 255, 0.925);
  font-weight: bold;
}
.gantt-block .no_progress_drag .gantt_task_progress_drag {
  display: none !important;
}
.gantt-block .no_link_drag .gantt_link_control {
  display: none;
}
.gantt-block .gantt_task_content {
  padding-left: 15px;
}
.gantt-block .gantt_side_content.gantt_right {
  padding-left: 22px;
}
.gantt-block #gantt-loader {
  border: 12px solid #f3f3f3;
  border-radius: 50%;
  border-top: 12px solid #444;
  width: 70px;
  height: 70px;
  animation: spin 1s linear infinite;
  display: block;
  visibility: visible;
}
.gantt-block .spin-center {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  right: 0;
  margin: auto;
  z-index: 10003;
}
.gantt-block .gantt_milestone .gantt_task_content {
  height: 19px;
  width: 19px;
  translate: -50% 20%;
  color: transparent;
}

.gantt_modal_box .lag-input {
  font-size: 13px !important;
  font-weight: normal;
  width: 50px;
}

.gantt_cal_light input[type=radio] {
  margin: -3px 3px 0 7px;
}

.gantt_tooltip {
  box-sizing: border-box;
  overflow-wrap: break-word;
}
.gantt_tooltip .capitalize {
  text-transform: capitalize !important;
}

.gantt_tooltip_description {
  padding-left: 25px;
  max-width: 650px;
  max-height: 150px;
  overflow-x: auto;
  overflow-y: auto;
  word-wrap: break-word;
  white-space: pre-wrap;
}

#page #gantt-loader-overlay {
  background-color: black;
  height: 100%;
  left: 0;
  position: absolute;
  top: 0;
  width: 100%;
  z-index: 10002;
  opacity: 0.15;
}

.gantt_today, .gantt_marker.today {
  color: var(--dhx-gantt-base-colors-white);
  font-size: var(--dhx-gantt-caption-font-size);
  line-height: var(--dhx-gantt-caption-line-height);
  font-weight: var(--dhx-gantt-caption-line-weight);
}