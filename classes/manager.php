<?php

namespace enrol_arlo;

use enrol_arlo\Arlo\AuthAPI\Client;
use enrol_arlo\Arlo\AuthAPI\RequestUri;
use enrol_arlo\Arlo\AuthAPI\Filter;
use enrol_arlo\Arlo\AuthAPI\XmlDeserializer;
use enrol_arlo\Arlo\AuthAPI\Resource\AbstractCollection;
use enrol_arlo\Arlo\AuthAPI\Resource\AbstractResource;
use enrol_arlo\Arlo\AuthAPI\Resource\ApiException;
use enrol_arlo\Arlo\AuthAPI\Resource\Event;
use enrol_arlo\Arlo\AuthAPI\Resource\EventTemplate;
use enrol_arlo\Arlo\AuthAPI\Resource\OnlineActivity;
use enrol_arlo\exception\client_exception;
use enrol_arlo\exception\server_exception;
use enrol_arlo\utility\date;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;


class manager {
    /** @var $plugin enrolment plugin instance. */
    private static $plugin;
    private $platform;
    private $apiusername;
    private $apipassword;
    private $trace;

    public function __construct(\progress_trace $trace = null) {
        // Setup trace.
        if (is_null($trace)) {
            $this->trace = new \null_progress_trace();
        } else {
            $this->trace = $trace;
        }
        self::$plugin = enrol_get_plugin('arlo');
    }

    public function process_instances() {
        global $DB;
        $conditions = array(
            'enrol' => 'arlo',
            'status' => ENROL_INSTANCE_ENABLED,
            'platform' => $this->platform
        );
        $sql = "SELECT ai.* 
                  FROM {enrol} e
                  JOIN {enrol_arlo_instance} ai
                    ON ai.enrolid = e.id
                 WHERE e.enrol = :enrol 
                   AND e.status = :status
                   AND ai.platform = :platform
              ORDER BY ai.nextpulltime";

        $records = $DB->get_records_sql($sql, $conditions);
        foreach ($records as $record) {
            self::fetch_instance_response($record);
        }

    }

    public static function update_api_status($status) {
        if (!is_int($status)) {
            throw new \Exception('API Status must integer.');
        }
        self::$plugin->set_config('apistatus', $status);
    }

    public static function get_collection_sync_info($collection) {
        global $DB;
        $conditions = array('type' => $collection);
        $record = $DB->get_record('enrol_arlo_collection', $conditions);
        if (!$record) {
            $record                         = new \stdClass();
            $record->platform               = self::$plugin->get_config('platform');
            $record->type                   = $collection;
            $record->latestsourcemodified   = '';
            $record->nextpulltime           = 0;
            $record->endpulltime            = 0;
            $record->lastpulltime           = 0;
            $record->lasterror              = '';
            $record->errorcount             = 0;
            $record->id = $DB->insert_record('enrol_arlo_collection', $record);
        }
        return $record;
    }

    /**
     * @param \stdClass $record
     * @param bool $hasnext
     * @return \stdClass
     */
    public static function update_collection_sync_info(\stdClass $record, $hasnext= false) {
        global $DB;

        $record->lastpulltime = time();
        // Only update nextpulltime if no more records to process.
        if (!$hasnext) {
            $record->nextpulltime = time();
        }
        $DB->update_record('enrol_arlo_collection', $record);
        return $record;
    }

    public function update_events($manualoverride = false) {
        $timestart = microtime();
        self::trace("Updating Events");
        try {
            $hasnext = true; // Initialise to for multiple pages.
            while ($hasnext) {
                $hasnext = false; // Avoid infinite loop by default.
                // Get sync information.
                $syncinfo = self::get_collection_sync_info('events');
                // Setup RequestUri for getting Events.
                $requesturi = new RequestUri();
                $requesturi->setResourcePath('events/');
                $requesturi->addExpand('Event/EventTemplate');
                $request = new collection_request($syncinfo, $requesturi, $manualoverride);
                if (!$request->executable()) {
                    self::trace('Cannot execute request due to timing or API status');
                } else {
                    $response = $request->execute();
                    $collection = self::deserialize_response_body($response);
                    // Any returned.
                    if (empty($collection)) {
                        self::update_collection_sync_info($syncinfo, $hasnext);
                        self::trace("No new or updated resources found.");
                    } else {
                        foreach ($collection as $event) {
                            $record = self::update_event($event);
                            $latestmodified = $event->LastModifiedDateTime;
                            $syncinfo->latestsourcemodified = $latestmodified;
                        }
                        $hasnext = (bool) $collection->hasNext();
                        self::update_collection_sync_info($syncinfo, $hasnext);
                    }
                }
            }
        } catch (\Exception $e) {
            print_object($e); // TODO handle XMLParse and Moodle exceptions.
            die;
        }
        $timefinish = microtime();
        $difftime = microtime_diff($timestart, $timefinish);
        self::trace("Execution took {$difftime} seconds");
        return true;
    }

    public function update_onlineactivities($manualoverride = false) {
        $timestart = microtime();
        self::trace("Updating Online Activities");
        try {
            $hasnext = true; // Initialise to for multiple pages.
            while ($hasnext) {
                $hasnext = false; // Avoid infinite loop by default.
                // Get sync information.
                $syncinfo = self::get_collection_sync_info('onlineactivities');
                // Setup RequestUri for getting Events.
                $requesturi = new RequestUri();
                $requesturi->setResourcePath('onlineactivities/');
                $requesturi->addExpand('Event/EventTemplate');
                $request = new collection_request($syncinfo, $requesturi, $manualoverride);
                if (!$request->executable()) {
                    self::trace('Cannot execute request due to timing or API status');
                } else {
                    $response = $request->execute();
                    $collection = self::deserialize_response_body($response);
                    // Any returned.
                    if (empty($collection)) {
                        self::update_collection_sync_info($syncinfo, $hasnext);
                        self::trace("No new or updated resources found.");
                    } else {
                        foreach ($collection as $onlineactivity) {
                            $record = self::update_onlineactivity($onlineactivity);
                            $latestmodified = $onlineactivity->LastModifiedDateTime;
                            $syncinfo->latestsourcemodified = $latestmodified;
                        }
                        $hasnext = (bool) $collection->hasNext();
                        self::update_collection_sync_info($syncinfo, $hasnext);
                    }
                }
            }
        } catch (\Exception $e) {
            print_object($e); // TODO handle XMLParse and Moodle exceptions.
            die;
        }
        $timefinish = microtime();
        $difftime = microtime_diff($timestart, $timefinish);
        self::trace("Execution took {$difftime} seconds");
        return true;
    }

    public function update_templates($manualoverride = false) {
        $timestart = microtime();
        self::trace("Updating Templates");
        try {
            $hasnext = true; // Initialise to for multiple pages.
            while ($hasnext) {
                $hasnext = false; // Avoid infinite loop by default.
                // Get sync information.
                $syncinfo = self::get_collection_sync_info('eventtemplates');
                // Setup RequestUri for getting Templates.
                $requesturi = new RequestUri();
                $requesturi->setResourcePath('eventtemplates/');
                $requesturi->addExpand('EventTemplate');
                $request = new collection_request($syncinfo, $requesturi, $manualoverride);
                if (!$request->executable()) {
                    self::trace('Cannot execute request due to timing or API status');
                } else {
                    $response = $request->execute();
                    $collection = self::deserialize_response_body($response);
                    // Any returned.
                    if (empty($collection)) {
                        self::update_collection_sync_info($syncinfo, $hasnext);
                        self::trace("No new or updated resources found.");
                    } else {
                        foreach ($collection as $template) {
                            $record = self::update_template($template);
                            $latestmodified = $template->LastModifiedDateTime;
                            $syncinfo->latestsourcemodified = $latestmodified;
                        }
                        $hasnext = (bool) $collection->hasNext();
                        self::update_collection_sync_info($syncinfo, $hasnext);
                    }
                }
            }
        } catch (\Exception $e) {
            print_object($e); // TODO handle XMLParse and Moodle exceptions.
            die;
        }
        $timefinish = microtime();
        $difftime = microtime_diff($timestart, $timefinish);
        self::trace("Execution took {$difftime} seconds");
        return true;
    }

    private function deserialize_response_body(Response $response) {
        // Returned HTTP status, used for error checking.
        $status = (int) $response->getStatusCode();
        $reason = $response->getReasonPhrase();
        // Incorrect content-type.
        $contenttype = $response->getHeaderLine('content-type');
        if (strpos($contenttype, 'application/xml') === false) {
            throw new server_exception(
                $reason,
                $status,
                'error_incorrectcontenttype',
                array('contenttype' => $contenttype)
            );
        }
        // Deserialize response body.
        $deserializer = new XmlDeserializer('\enrol_arlo\Arlo\AuthAPI\Resource\\');
        $stream = $response->getBody();
        $contents = $stream->getContents();
        if ($stream->eof()) {
            $stream->rewind(); // Rewind stream.
        }
        // If everything went OK a resource class will be returned.
        return $deserializer->deserialize($contents);
    }

    public function update_event(Event $event) {
        global $DB;

        $record                 = new \stdClass();
        $record->platform       = $this->platform;
        $record->sourceid       = $event->EventID;
        $record->sourceguid     = $event->UniqueIdentifier;

        $record->code           = $event->Code;
        $record->startdatetime  = $event->StartDateTime;
        $record->finishdatetime = $event->FinishDateTime;

        $record->sourcestatus   = $event->Status;
        $record->sourcecreated  = $event->CreatedDateTime;
        $record->sourcemodified = $event->LastModifiedDateTime;
        $record->modified       = time();

        $template = $event->getEventTemplate();
        if ($template) {
            $record->sourcetemplateid       = $template->TemplateID;
            $record->sourcetemplateguid     = $template->UniqueIdentifier;
        }

        $params = array(
            'platform'      => self::$plugin->get_config('platform'),
            'sourceid'      => $record->sourceid,
            'sourceguid'    => $record->sourceguid
        );
        $record->id = $DB->get_field('enrol_arlo_event', 'id', $params);
        if (empty($record->id)) {
            unset($record->id);
            $record->id = $DB->insert_record('enrol_arlo_event', $record);
            self::trace(sprintf('Created: %s', $record->code));
        } else {
            $DB->update_record('enrol_arlo_event', $record);
            self::trace(sprintf('Updated: %s', $record->code));
        }
        return $record;
    }

    public function update_template(EventTemplate $template) {
        global $DB;

        $record = new \stdClass();
        $record->platform       = $this->platform;
        $record->sourceid       = $template->TemplateID;
        $record->sourceguid     = $template->UniqueIdentifier;
        $record->name           = $template->Name;
        $record->code           = $template->Code;
        $record->sourcestatus   = $template->Status;
        $record->sourcecreated  = $template->CreatedDateTime;
        $record->sourcemodified = $template->LastModifiedDateTime;
        $record->modified       = time();

        $params = array(
            'platform'      => self::$plugin->get_config('platform'),
            'sourceid'      => $record->sourceid,
            'sourceguid'    => $record->sourceguid
        );
        $record->id = $DB->get_field('enrol_arlo_template', 'id', $params);
        if (empty($record->id)) {
            unset($record->id);
            $record->id = $DB->insert_record('enrol_arlo_template', $record);
            self::trace(sprintf('Created: %s', $record->name));
        } else {
            $DB->update_record('enrol_arlo_template', $record);
            self::trace(sprintf('Updated: %s', $record->name));
        }
        return $record;
    }

    public function update_onlineactivity(OnlineActivity $onlineactivity) {
        global $DB;
        $record = new \stdClass();
        $record->platform       = $this->platform;
        $record->sourceid       = $onlineactivity->OnlineActivityID;
        $record->sourceguid     = $onlineactivity->UniqueIdentifier;
        $record->name           = $onlineactivity->Name;
        $record->code           = $onlineactivity->Code;
        $record->contenturi     = $onlineactivity->ContentUri;
        $record->sourcestatus   = $onlineactivity->Status;
        $record->sourcecreated  = $onlineactivity->CreatedDateTime;
        $record->sourcemodified = $onlineactivity->LastModifiedDateTime;
        $record->modified       = time();

        $template = $onlineactivity->getEventTemplate();
        if ($template) {
            $record->sourcetemplateid       = $template->TemplateID;
            $record->sourcetemplateguid     = $template->UniqueIdentifier;
        }

        $params = array(
            'platform'      => $this->platform,
            'sourceid'      => $record->sourceid,
            'sourceguid'    => $record->sourceguid
        );
        $record->id = $DB->get_field('enrol_arlo_onlineactivity', 'id', $params);
        if (empty($record->id)) {
            unset($record->id);
            $record->id = $DB->insert_record('enrol_arlo_onlineactivity', $record);
            self::trace(sprintf('Created: %s', $record->name));
        } else {
            $DB->update_record('enrol_arlo_onlineactivity', $record);
            self::trace(sprintf('Updated: %s', $record->name));
        }
        return $record;
    }

    /**
     * Output a progress message.
     *
     * @param $message the message to output.
     * @param int $depth indent depth for this message.
     */
    private function trace($message, $depth = 0) {
        $this->trace->output($message, $depth);
    }
}
