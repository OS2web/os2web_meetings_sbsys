<?php

namespace Drupal\os2web_meetings_sbsys\Plugin\migrate\source;

use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\node\Entity\Node;
use Drupal\os2web_meetings\Entity\Meeting;
use Drupal\os2web_meetings\Form\SettingsForm;
use Drupal\os2web_meetings\Plugin\migrate\source\MeetingsDirectory;

/**
 * Source plugin for retrieving data via URLs.
 *
 * @MigrateSource(
 *   id = "os2web_meetings_directory_sbsys"
 * )
 */
class MeetingsDirectorySbsys extends MeetingsDirectory {

  /**
   * {@inheritdoc}
   */
  public function getMeetingsManifestPath() {
    return \Drupal::config(SettingsForm::$configName)
      ->get('sbsys_meetings_manifest_path');
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaAccessToCanonical(array $source) {
    if (strcasecmp($source['agenda_access'], 'true') === 0) {
      return MeetingsDirectory::AGENDA_ACCESS_OPEN;
    }
    else {
      return MeetingsDirectory::AGENDA_ACCESS_CLOSED;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaTypeToCanonical(array $source) {
    if (strcasecmp($source['agenda_type'], 'referat') === 0) {
      return MeetingsDirectory::AGENDA_TYPE_REFERAT;
    }
    else {
      return MeetingsDirectory::AGENDA_TYPE_DAGSORDEN;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function convertStartDateToCanonical(array $source) {
    $start_date = $source['meeting_start_date'] . ' ' . $source['meeting_start_time'];

    return $this->convertDateToTimestamp($start_date);
  }

  /**
   * {@inheritdoc}
   */
  public function convertEndDateToCanonical(array $source) {
    $end_date = $source['meeting_end_date'] . ' ' . $source['meeting_end_time'];

    return $this->convertDateToTimestamp($end_date);
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaDocumentToCanonical(array $source) {
    $title = 'Samlet document';
    // There is no reference to HTML file, but we expect it to be in the
    // directory with the following name.
    $uri = 'dagsorden.html';

    return [
      'title' => $title,
      'uri' => $uri,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertCommitteeToCanonical(array $source) {
    $id = $source['committee_id'];
    $name = $source['committee_name'];

    return [
      'id' => $id,
      'name' => $name,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertLocationToCanonical(array $source) {
    $name = $source['location_name'];
    // We don't have an ID for the location, use name instead.
    $id = $name;

    return [
      'id' => $id,
      'name' => $name,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertBulletPointsToCanonical(array $source) {
    $canonical_bullet_points = [];
    $source_bullet_points = $source['bullet_points'];

    foreach ($source_bullet_points as $bullet_point) {
      $id = $bullet_point['@attributes']['DagsordenpunktID'];
      $bpNumber = $bullet_point['@attributes']['Nummer'];
      $title = $bullet_point['Overskrift'];
      $access = filter_var($bullet_point['@attributes']['Åbent'], FILTER_VALIDATE_BOOLEAN);

      // Getting attachments (text).
      $source_attachments = $bullet_point['Indhold'];
      $canonical_attachments = [];
      if (is_array($source_attachments)) {
        // Handling single items.
        if (array_key_exists('@attributes', $source_attachments)) {
          $source_attachments = [$source_attachments];
        }

        $canonical_attachments = $this->convertAttachmentsToCanonical($source_attachments);
      }

      // Getting enclosures (files).
      $source_enclosures = $bullet_point['Bilagsliste']['Bilag'];
      $canonical_enclosures = [];
      if (is_array($source_enclosures)) {
        // Handling single items.
        if (array_key_exists('@attributes', $source_enclosures)) {
          $source_enclosures = [$source_enclosures];
        }

        $canonical_enclosures = $this->convertEnclosuresToCanonical($source_enclosures);
      }

      $canonical_bullet_points[] = [
        'id' => $id,
        'number' => $bpNumber,
        'title' => $title,
        'access' => $access,
        'attachments' => $canonical_attachments,
        'enclosures' => $canonical_enclosures,
      ];
    }

    return $canonical_bullet_points;
  }

  /**
   * {@inheritdoc}
   */
  public function convertAttachmentsToCanonical(array $source_attachments) {
    $canonical_attachments = [];

    foreach ($source_attachments as $title => $body) {
      // Using title as ID, as we don't have a real one.
      $id = $title;

      $canonical_attachments[] = [
        'id' => $id,
        'title' => $title,
        'body' => $body,
        'access' => TRUE,
      ];
    }

    return $canonical_attachments;
  }

  /**
   * {@inheritdoc}
   */
  public function convertEnclosuresToCanonical(array $source_enclosures) {
    $canonical_enclosures = [];

    foreach ($source_enclosures as $enclosure) {
      $id = $enclosure['@attributes']['BilagID'];
      $title = $enclosure['@attributes']['Navn'];
      $access = filter_var($enclosure['@attributes']['MaaPubliceres'], FILTER_VALIDATE_BOOLEAN);
      $uri = $enclosure['@attributes']['BilagUrl'];

      $canonical_enclosures[] = [
        'id' => $id,
        'title' => $title,
        'uri' => $uri,
        'access' => $access,
      ];
    }

    return $canonical_enclosures;
  }

  /**
   * Converts Danish specific string date into timestamp in UTC.
   *
   * @param string $dateStr
   *   Date as string, e.g. "27. august 2018 16:00".
   *
   * @return int
   *   Timestamp in UTC.
   *
   * @throws \Exception
   */
  private function convertDateToTimestamp($dateStr) {
    $dateStr = str_ireplace([
      ". januar ",
      ". februar ",
      ". marts ",
      ". april ",
      ". maj ",
      ". juni ",
      ". juli ",
      ". august ",
      ". september ",
      ". oktober ",
      ". november ",
      ". december ",
    ],
      [
        "-1-",
        "-2-",
        "-3-",
        "-4-",
        "-5-",
        "-6-",
        "-7-",
        "-8-",
        "-9-",
        "-10-",
        "-11-",
        "-12-",
      ], $dateStr);

    $dateTime = new \DateTime($dateStr, new \DateTimeZone('Europe/Copenhagen'));

    return $dateTime->getTimestamp();
  }

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    parent::postImport($event);

    // Find all meetings.
    $query = \Drupal::entityQuery('node');
    $query->condition('type', 'os2web_meetings_meeting');
    $query->condition('field_os2web_m_source', $this->getPluginId());
    $entity_ids = $query->execute();

    $meetings = Node::loadMultiple($entity_ids);

    // Group meetings as:
    // $groupedMeetings[<meeting_id>][<agenda_id>] = <node_id> .
    $groupedMeetings = [];
    foreach ($meetings as $meeting) {
      $os2webMeeting = new Meeting($meeting);

      $meeting_id = $os2webMeeting->getMeetingId();
      $agenda_id = $os2webMeeting->getEsdhId();

      $groupedMeetings[$meeting_id][$agenda_id] = $os2webMeeting->id();

      // Sorting agendas, so that lowest agenda ID is always the first.
      sort($groupedMeetings[$meeting_id]);
    }

    // Process grouped meetings and set addendum fields.
    foreach ($groupedMeetings as $meeting_id => $agendas) {
      // Skipping if agenda count is 1.
      if (count($agendas) == 1) {
        continue;
      }

      $mainAgendaNodedId = array_shift($agendas);

      foreach ($agendas as $agenda_id => $node_id) {
        // Getting the meeting.
        $os2webMeeting = new Meeting($meetings[$node_id]);

        // Setting addendum field, meeting is saved inside a function.
        $os2webMeeting->setAddendum($mainAgendaNodedId);
      }
    }
  }

}
