<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * rest api manager for pod
 *
 * @package repository_pod
 * @copyright  2020 unistra  {@link http://unistra.fr}
 * @author     Celine Perves <cperves@unistra.fr>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace repository_pod\manager;
defined('MOODLE_INTERNAL') || die();
global $CFG;

class repository_pod_api_manager {
    const POD_NOTPOD = -2;
    const POD_EXISTS = 1;
    const POD_NOTEXISTS = 0;
    const POD_SERVERKO = -1;

    const MEDIATYPE_AUDIO = "audio";
    const MEDIATYPE_VIDEO = "video";
    private $client = null;
    private $options;

    public function __construct($options) {
        $this->options = $options;
        try {
            $this->client = new \curl();
            $this->client->setHeader('Authorization: Token '.$this->options['pod_api_key']);
            $this->client->setHeader('Content-Type: application/json');
        } catch (Exception $e) {
            debugging('Error while retrieving pod rest client : '.$e->getTrace());
        }
    }

    public function execute_request($func, $params, $method = 'GET') {
        if (!is_array($params)) {
            return false;
        }
        $res = array();
        $response = $this->client->get($this->options['pod_url'].$func, $params);
        if ($this->client->get_info()->http_code == "404") {
            return false;
        }
        $datas = json_decode($response);
        if (!empty($datas)) {
            if (!is_array($datas) && property_exists($datas, 'results')) {
                foreach ($datas->results as $data) {
                    array_push($res, $data);
                }
                $numpages = (int)ceil($datas->count / $this->options['page_size']);
                $page = isset($params['page']) ? $params['page'] : 1;
                return array('page' => $page, 'results' => $res, 'pages' => $numpages, 'total' => $datas->count);
            } else {
                return $datas;
            }
        } else {
            return false;
        }
    }
    public function get_all_encoded_files($resultarray) {
        global $OUTPUT;
        $https = (boolean) $this->options['https'];
        // Get all encoded files for one username.
        $list = array();
        $list['total'] = $resultarray['total'];
        $list['pages'] = $resultarray['pages'];
        $list['perpage'] = $this->options['page_size'];
        $list['page'] = $resultarray['page'];
        $list['norefresh'] = true;
        $results = $resultarray['results'];
        if (count($results) > 0) {
            $list['list'] = array();
            $i = 0;
            foreach ($results as $result) {
                $videodata = $result->video_data;
                $mediatype = $videodata->mediatype;
                if (!in_array($mediatype, array(self::MEDIATYPE_AUDIO, self::MEDIATYPE_VIDEO))) {
                    continue;
                }
                $podcourseid = $result->id;
                $title = $videodata->title;
                $url = ($https ? 'https:' : 'http:') . $videodata->full_url;
                $source = $podcourseid;
                $author = $videodata->owner;
                // TODO alternatives owner.
                $datemodified = strtotime($result->date_added);
                $datecreated = $result->date_evt;
                if ($datecreated === null) {
                    $datecreated = $datemodified;
                } else {
                    $datecreated = strtotime($datecreated);
                }
                $duration = $result->duration;
                $license = get_string("podlicenceinformationunavailable", "repository_pod");
                if (property_exists($videodata, 'video_files')) {

                    foreach ($videodata->video_files as $videoextensions) {
                        foreach ($videoextensions as $videofile) {

                                // TODO here include extension checking.
                                $list['list'][$i] = array(
                                    'title' => $title . $videofile->extension,
                                    'url' => $url,
                                    'source' => $source,
                                    'extension' => $videofile->extension,
                                    'datecreated' => $datecreated,
                                    'datemodified' => $datemodified,
                                    'size' => null,
                                    'author' => $author,
                                    'license' => $license
                                );
                                $elses = $OUTPUT->image_url(file_extension_icon($videofile->extension, 80))->out(false);
                                try {
                                    if (!isset($this->options['thumbnail'])) {
                                        if (property_exists($videodata, "thumbnail")) {
                                            $list["list"][$i]["thumbnail"] = ($https ? 'https:' : 'http:') . $videodata->thumbnail;
                                        } else {
                                            $list["list"][$i]["thumbnail"] = $elses;

                                        }
                                    } else {
                                        $list["list"][$i]["thumbnail"] = $elses;
                                    }
                                } catch (Exception $e) {
                                    $list["list"][$i]["thumbnail"] = $elses;
                                }
                                $i++;
                                // Only one item per extension.
                                break;
                        }

                    }

                }
            }

        } else {
            $list['list'] = array();
        }
        return $list;
    }
    /**
     * check if the current resource is pod type and exists on pod
     * @param $ctxid module contextid
     * @throws \moodle_exception
     * @return boolean 2 if not a pod resource,if pod and exist on pod return 1, 0 if pod and not exist on pod ,-1 pod not respond
     */
    public static function check_resource_exists_from_contextid($ctxid) {
        global $DB;
        $sql = "SELECT r.type,f.source
                FROM mdl_files f INNER JOIN {files_reference} fr ON fr.id=f.referencefileid
                INNER JOIN {repository_instances} ri  on fr.repositoryid=ri.id INNER JOIN {repository} r on r.id=ri.typeid
                WHERE f.contextid=:ctxid  AND r.type=:type AND f.component=:component AND f.filearea=:filearea";
        $record = $DB->get_record_sql($sql,
            array('ctxid' => $ctxid, 'type' => 'pod', 'component' => 'mod_resource', 'filearea' => 'content'));
        if ($record && $record->type == 'pod') {
            // Check pod resource exists.
            $podrestapimanager = new repository_pod_api_manager();
            $podresourceid = $record->source;
            $params = array(
                "format" => "json",
                "encoding_in_progress" => "False"
            );
            $result = $podrestapimanager->execute_request("/rest/videos/$podresourceid/?", $params);
            if (! $result) {
                return self::POD_SERVERKO;
            }
            if (!empty($result)) {
                return self::POD_EXISTS;
            } else {
                return self::POD_NOTEXISTS;
            }
        }
        return self::POD_NOTPOD;
    }
}
