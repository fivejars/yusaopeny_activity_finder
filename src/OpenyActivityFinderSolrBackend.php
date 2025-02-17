<?php

namespace Drupal\openy_activity_finder;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\Core\Url;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\search_api\Query\ResultSet;
use Drupal\search_api\SearchApiException;

/**
 * {@inheritdoc}
 */
class OpenyActivityFinderSolrBackend extends OpenyActivityFinderBackend {

  // 1 day for cache.
  const CACHE_TTL = 86400;

  // Number of results to retrieve per page.
  const TOTAL_RESULTS_PER_PAGE = 25;

  // Cache ID for locations info.
  const ACTIVITY_FINDER_CACHE_TAG = 'openy_activity_finder:default';

  // Default location types.
  const DEFAULT_LOCATION_TYPES = ['branch' => 'branch', 'camp' => 'camp', 'facility' => 'facility'];

  /**
   * Cache default.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Time manager needed for calculating expire for caches.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $loggerChannel;

  /**
   * Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Creates a new RepeatController.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache default.
   * @param \Drupal\Core\Database\Connection $database
   *   The Database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The Date formatter.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel
   *   Logger service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Module Handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, CacheBackendInterface $cache, Connection $database, EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, TimeInterface $time, LoggerChannelInterface $loggerChannel, ModuleHandlerInterface $module_handler) {
    parent::__construct($config_factory);
    $this->cache = $cache;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->loggerChannel = $loggerChannel;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function runProgramSearch($parameters, $log_id) {
    // Make a request to Search API.
    $results = $this->doSearchRequest($parameters);

    // Get results count.
    $data['count'] = $results->getResultCount();

    // Get facets and enrich, sort data, add static filters.
    $data['facets'] = $this->getFacets($results);

    // Set pager as current page number.
    $data['pager'] = isset($parameters['page']) && $data['count'] > self::TOTAL_RESULTS_PER_PAGE ? $parameters['page'] : 0;

    // Get pager structure.
    $data['pager_info'] = $this->getPages($data['count']);

    // Process results.
    $data['table'] = $this->processResults($results, $log_id);

    $locations = $this->getLocations();
    foreach ($locations as $key => $group) {
      $locations[$key]['count'] = 0;
      foreach ($group['value'] as $location) {
        if (isset($data['facets']['locations'])) {
          if (is_array($data['facets']['locations']) || is_object($data['facets']['locations'])) {
            foreach ($data['facets']['locations'] as $fl) {
              if (isset($fl['id']) && isset($location['value']) && $fl['id'] == $location['value']) {
                $locations[$key]['count'] += $fl['count'];
              }
            }
          }
        }
      }
    }
    $data['groupedLocations'] = $locations;

    $data['sort'] = $parameters['sort'] ?? 'title__ASC';

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function doSearchRequest($parameters) {
    $index_id = $this->config->get('index') ? $this->config->get('index') : 'default';
    $index = Index::load($index_id);
    $query = $index->query();
    $keys = !empty($parameters['keywords']) ? $parameters['keywords'] : '';
    if ($keys) {
      $query->keys($keys);
    }
    $query->setFulltextFields([
      'title',
      'field_session_description',
      'class_title',
      'field_class_description',
      'activity_title',
      'field_activity_description',
      'field_category_program',
      'field_program_description',
      'category_title',
      'field_category_description',
    ]);
    $query->addCondition('status', 1);

    if (!empty($parameters['ages'])) {
      $ages = explode(',', rawurldecode($parameters['ages']));
      $query->addCondition('af_ages_min_max', $ages, 'IN');
    }

    if (!empty($parameters['weeks'])) {
      $weeks = explode(',', rawurldecode($parameters['weeks']));
      $query->addCondition('af_weeks', $weeks, 'IN');
    }

    if (!empty($parameters['days'])) {
      $days_ids = explode(',', rawurldecode($parameters['days']));
      // Convert ids to search value.
      $days_info = $this->getDaysOfWeek();
      foreach ($days_info as $i) {
        if (in_array($i['value'], $days_ids)) {
          $days[] = $i['search_value'];
        }
      }
      $query->addCondition('field_session_time_days', $days, 'IN');
    }

    if (!empty($parameters['times'])) {
      $times = explode(',', rawurldecode($parameters['times']));
      $query->addCondition('af_parts_of_day', $times, 'IN');
    }

    if (!empty($parameters['daystimes'])) {
      $daystimes = explode(',', rawurldecode($parameters['daystimes']));
      $query->addCondition('af_weekdays_parts_of_day', $daystimes, 'IN');
    }

    if (!empty($parameters['program_types'])) {
      $program_types = explode(',', rawurldecode($parameters['program_types']));

    }

    $category_program_info = $this->getCategoryProgramInfo();
    if (!empty($parameters['categories'])) {
      $categories_nids = explode(',', rawurldecode($parameters['categories']));
      // Map nids to titles.
      foreach ($categories_nids as $nid) {
        $categories[] = !empty($category_program_info[$nid]['title']) ? $nid : '';

        // Subcategories with the same title could belong to different categories.
        // Additional category filter is essential.
        if (!empty($category_program_info[$nid]['program']['title'])) {
          $program_types[] = $category_program_info[$nid]['program']['title'];
        }

      }

      if ($categories) {
        $query->addCondition('field_activity_category', $categories, 'IN');
      }

    }
    // Ignore sessions which don't have referenced activity.
    else {
      $query->addCondition('field_activity_category', NULL, '<>');
    }

    if (!empty($program_types)) {
      $query->addCondition('field_category_program', $program_types, 'IN');
    }

    // Limit categories.
    $limit_nids = [];
    $limit_nids_config = [];
    if (!empty($parameters['limit'])) {
      $limit_nids = explode(',', $parameters['limit']);
    }
    if (!empty($this->config->get('limit'))) {
      $limit_nids_config = explode(',', $this->config->get('limit'));
    }
    $limit_nids = array_merge($limit_nids, $limit_nids_config);
    $limit_categories = [];
    foreach ($limit_nids as $nid) {
      if (empty($category_program_info[$nid]['title'])) {
        continue;
      }
      $limit_categories[] = $nid;
    }
    if ($limit_categories) {
      $query->addCondition('field_activity_category', $limit_categories, 'IN');
    }

    // Ensure to exclude categories.
    $exclude_nids = [];
    if (!empty($parameters['exclude'])) {
      $exclude_nids = explode(',', $parameters['exclude']);
    }
    $exclude_nids_config = explode(',', $this->config->get('exclude'));
    $exclude_nids = array_merge($exclude_nids, $exclude_nids_config);
    $exclude_categories = [];
    foreach ($exclude_nids as $nid) {
      if (empty($category_program_info[$nid]['title'])) {
        continue;
      }
      $exclude_categories[] = $nid;
    }
    if ($exclude_categories) {
      $query->addCondition('field_activity_category', $exclude_categories, 'NOT IN');
    }

    // Select locations based on filters.
    $locations_info = $this->getLocationsInfo();
    // Only use locations selected in parameters, if specified.
    if (!empty($parameters['locations'])) {
      $locations_nids = explode(',', rawurldecode($parameters['locations']));
      foreach ($locations_info as $key => $item) {
        if (in_array($item['nid'], $locations_nids)) {
          $locations[] = $key;
        }
      }
    }
    // Otherwise filter on all locations configured in settings.
    else {
      foreach ($locations_info as $key => $item) {
        $locations[] = $key;
      }
    }
    $query->addCondition('field_session_location', $locations, 'IN');

    $query->range(0, self::TOTAL_RESULTS_PER_PAGE);
    // Use pager if parameter has been provided.
    if (isset($parameters['page'])) {
      $offset = self::TOTAL_RESULTS_PER_PAGE * $parameters['page'] - self::TOTAL_RESULTS_PER_PAGE;
      $query->range($offset, self::TOTAL_RESULTS_PER_PAGE);
    }
    // Set up default sort as relevance and expose if manual sort has been provided.
    // $query->sort('search_api_relevance', 'DESC');.
    if (empty($parameters['sort'])) {
      $query->sort('title', 'ASC');
    }
    else {
      $sort = explode('__', $parameters['sort']);
      $sort_by = $sort[0];
      $sort_mode = $sort[1];
      $query->sort($sort_by, $sort_mode);
    }

    $server = $index->getServerInstance();
    if ($server->supportsFeature('search_api_facets')) {
      $filters = $this->getFilters();
      $query->setOption('search_api_facets', $filters);
    }
    else {
      $this->loggerChannel->info(t('Search server doesn\'t support facets (filters). '));
    }
    $query->addTag('af_search');
    $results = $query->execute();

    return $results;
  }

  /**
   * Process results function.
   *
   * @param \Drupal\search_api\Query\ResultSet $results
   *   Search results to process.
   * @param string $log_id
   *   Id of the Search Log needed for tracking Register / Details actions.
   *
   * @return array
   *   Processed search results.
   *
   * @throws \Exception
   */
  public function processResults(ResultSet $results, $log_id) {
    $data = [];
    $locations_info = $this->getLocationsInfo();
    /** @var \Drupal\search_api\Item\Item $result_item */
    foreach ($results->getResultItems() as $result_item) {
      try {
        $entity = $result_item->getOriginalObject()->getValue();
        if (!$entity) {
          $this->loggerChannel->error('Failed to load original object ' . $result_item->getId());
          continue;
        }
      }
      catch (SearchApiException $e) {
        // If we couldn't load the object, just log an error and fail
        // silently to set the values.
        $this->loggerChannel->error($e);
        continue;
      }
      $fields = $result_item->getFields();
      $dates = $entity->field_session_time ? $entity->field_session_time->referencedEntities() : [];
      $schedule_items = [];
      foreach ($dates as $date) {
        $_period = $date->field_session_time_date->getValue()[0];
        $_from = DrupalDateTime::createFromTimestamp(strtotime($_period['value'] . 'Z'), $this->timezone);
        $_to = DrupalDateTime::createFromTimestamp(strtotime($_period['end_value'] . 'Z'), $this->timezone);
        $days = [];
        foreach ($date->field_session_time_days->getValue() as $time_days) {
          $days[] = substr(ucfirst($time_days['value']), 0, 3);
        }
        $schedule_items[] = [
          'days' => implode(', ', $days),
          'time' => $_from->format('g:ia') . '-' . $_to->format('g:ia'),
        ];
        $from_md = $_from->format('M d');
        $to_md = $_to->format('M d');
        // For equal starting and ending dates show only starting date.
        $full_dates = $from_md == $to_md ? $from_md : $from_md . '-' . $to_md;

        // It is necessary to calculate not the number of full weeks,
        // but the number of sessions that takes place in the specified period.
        // I.e. we calculate the amount of the last day of the week
        // with the session (for example, Friday) in the period.
        $weeks = $this->countDaysByName(end($days), $_from->getPhpDateTime(), $_to->getPhpDateTime());
      }

      $availability_status = 'closed';
      if (!$entity->field_session_online->isEmpty()) {
        $availability_status = $entity->field_session_online->value ? 'open' : 'closed';
      }

      $availability_note = '';
      if ($availability_status == 'closed') {
        $availability_note = t('Registration closed')->__toString();
      }

      $class = $entity->field_session_class->entity;
      $activity = $class->field_class_activity->entity;
      $sub_category = $activity->field_activity_category->entity;
      $learn_more = '';
      if ($sub_category && $sub_category->hasField('field_learn_more')) {
        $link = $sub_category->field_learn_more->getValue();
        if (!empty($link[0]['uri'])) {
          $learn_more_view = $sub_category->field_learn_more->view();
          $learn_more = \Drupal::service('renderer')->render($learn_more_view)->__toString();
        }
      }

      $price = [];
      $nmbr_price = $entity->field_session_nmbr_price->value;
      $mbr_price = $entity->field_session_mbr_price->value;
      if (!empty($mbr_price) && $nmbr_price !== "-1.00" && $mbr_price !== "0.00") {
        $price[] = '$' . $entity->field_session_mbr_price->value . ' (member)';
      }

      $instructor = '';

      if (!empty($entity->field_session_instructor->value)) {
        $instructor = $entity->field_session_instructor->value;
      }

      if (!empty($nmbr_price) && $nmbr_price !== "-1.00" && $mbr_price !== "0.00") {
        $price[] = '$' . $entity->field_session_nmbr_price->value . ' (non-member)';
      }

      if ($nmbr_price == "-1.00") {
        $price[] = '$' . $mbr_price . ' (Member Only)';
      }
      elseif ($mbr_price == "0.00") {
        $price[] = 'Fee ' . '$' . $nmbr_price;
      }

      $activity_type = '';
      if (!empty($entity->field_activity_type->value)) {
        $activity_type = $entity->field_activity_type->value;
      }

      $atc_info = [];
      if ($activity_type == 'group') {
        // Create "Add to calendar" info for "group" activity types.
        // Example of calendar format 2018-08-21 14:15:00.
        $atc_info['time_start_calendar'] = DrupalDateTime::createFromTimestamp(strtotime($dates[0]->field_session_time_date->getValue()[0]['value'] . 'Z'), $this->timezone)->format('Y-m-d H:i:s');
        $atc_info['time_end_calendar'] = DrupalDateTime::createFromTimestamp(strtotime($dates[0]->field_session_time_date->getValue()[0]['end_value'] . 'Z'), $this->timezone)->format('Y-m-d H:i:s');
        $atc_info['timezone'] = date_default_timezone_get();
      }

      $item_data = [
        'nid' => $entity->id(),
        'availability_note' => $availability_note,
        'availability_status' => $availability_status,
        'activity_type' => $activity_type,
        'dates' => $full_dates ?? '',
        'weeks' => $weeks ?? '',
        'schedule' => $schedule_items,
        'days' => $schedule_items[0]['days'] ?? '',
        'times' => $schedule_items[0]['time'] ?? '',
        'location' => $fields['field_session_location']->getValues()[0],
        'location_id' => $locations_info[$fields['field_session_location']->getValues()[0]]['nid'],
        'location_info' => $locations_info[$fields['field_session_location']->getValues()[0]],
        'instructor' => $instructor,
        'log_id' => $log_id,
        'name' => $fields['title']->getValues()[0]->getText(),
        'price' => implode(', ', $price),
        'link' => Url::fromRoute('openy_activity_finder.register_redirect',
          ['log' => $log_id],
          ['query' => ['url' => $entity->field_session_reg_link->uri]])
          ->toString(TRUE)->getGeneratedUrl(),
        'description' => html_entity_decode(strip_tags(text_summary($entity->field_session_description->value ?? '', $entity->field_session_description->format, 600) ?? '')),
        'ages' => $this->convertData([$entity->field_session_min_age->value, $entity->field_session_max_age->value ?? '0']),
        'gender' => !empty($entity->field_session_gender->value) ? $entity->field_session_gender->value : '',
        // We keep empty variables in order to have the same structure with other backends (e.g. Daxko) for avoiding unexpected errors.
        'program_id' => $sub_category->id(),
        'offering_id' => '',
        'info' => [],
        'location_name' => '',
        'location_address' => '',
        'location_phone' => '',
        'spots_available' => !$entity->field_availability->isEmpty() ? $entity->field_availability->value : '',
        'status' => $availability_status,
        'note' => $availability_note,
        'learn_more' => !empty($learn_more) ? $learn_more : '',
        'more_results' => '',
        'more_results_type' => 'program',
        'program_name' => $fields['title']->getValues()[0]->getText(),
        'atc_info' => $atc_info,
      ];

      // Allow other modules to alter the process results.
      $this->moduleHandler
        ->alter('activity_finder_program_process_results', $item_data, $entity);

      $data[] = $item_data;
    }
    return $data;
  }

  /**
   * Get facets.
   *
   * @param \Drupal\search_api\Query\ResultSet $results
   *   Search results.
   *
   * @return mixed
   *   Facet data.
   */
  public function getFacets($results) {
    $facets = $results->getExtraData('search_api_facets', []);
    $locationsInfo = $this->getLocationsInfo();
    $category_program_info = $this->getCategoryProgramInfo();

    // Add static Age filter.
    $facets['static_age_filter'] = $this->getAges();

    // Add static Weeks filter.
    $facets['static_weeks_filter'] = $this->getWeeks();

    $facets_m = $facets;
    foreach ($facets as $f => $facet) {
      foreach ($facet as $i => $item) {
        if (!empty($item['filter'])) {
          // Remove double quotes.
          $facets_m[$f][$i]['filter'] = str_replace('"', '', $item['filter']);
        }
        if ($f == 'locations') {
          // For some reason if location doesn't contain any session
          // in filter we have '!' instead of location name.
          if (!empty($item['filter']) && $item['filter'] != '!') {
            $location_title = str_replace('"', '', $item['filter']);
            if (isset($locationsInfo[$location_title])) {
              $facets_m[$f][$i]['id'] = $locationsInfo[$location_title]['nid'];
            }
          }
        }
        if ($f == 'field_activity_category') {
          foreach ($category_program_info as $nid => $info) {
            $facets_m[$f][$i]['id'] = (int) $facets_m[$f][$i]['filter'];
          }
        }
        // Pass counters to static ages filter.
        if ($f == 'static_age_filter') {
          if (isset($facets['af_ages_min_max'])) {
            if (is_array($facets['af_ages_min_max']) || is_object($facets['af_ages_min_max'])) {
              foreach ($facets['af_ages_min_max'] as $info) {
                if ('"' . $item['value'] . '"' == $info['filter']) {
                  $facets_m[$f][$i]['count'] = $info['count'];
                }
              }
            }
          }
        }
        // Pass counters to static week filter.
        if ($f == 'static_weeks_filter') {
          $facets_m[$f][$i]['count'] = 0;
          foreach ($facets['af_weeks'] as $info) {
            if ('"' . $item['value'] . '"' == $info['filter']) {
              $facets_m[$f][$i]['count'] = $info['count'];
            }
          }
        }
      }
    }
    return $facets_m;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    $filters = [
      'field_session_min_age' => [
        'field' => 'field_session_min_age',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'field_session_max_age' => [
        'field' => 'field_session_max_age',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'field_category_program' => [
        'field' => 'field_category_program',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'field_activity_category' => [
        'field' => 'field_activity_category',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'locations' => [
        'field' => 'field_session_location',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'days_of_week' => [
        'field' => 'field_session_time_days',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'af_parts_of_day' => [
        'field' => 'af_parts_of_day',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'af_ages_min_max' => [
        'field' => 'af_ages_min_max',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'af_weeks' => [
        'field' => 'af_weeks',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
      'af_weekdays_parts_of_day' => [
        'field' => 'af_weekdays_parts_of_day',
        'limit' => 0,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
    ];
    return $filters;
  }

  /**
   * Get referencing chain for Session -> Program info.
   */
  public function getCategoryProgramInfo() {
    $data = [];
    $cid = 'openy_activity_finder:activity_program_info';
    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      $nids = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->condition('type', 'program_subcategory')
        ->condition('status', '1')
        ->accessCheck(FALSE)
        ->execute();
      $nids_chunked = array_chunk($nids, 20, TRUE);
      foreach ($nids_chunked as $chunked) {
        $program_subcategories = $this->entityTypeManager->getStorage('node')->loadMultiple($chunked);
        if (!empty($program_subcategories)) {
          foreach ($program_subcategories as $program_subcategory_node) {
            if ($program_node = $program_subcategory_node->field_category_program->entity) {
              $data[$program_subcategory_node->id()] = [
                'title' => $program_subcategory_node->label(),
                'program' => [
                  'nid' => $program_node->id(),
                  'title' => $program_node->label(),
                ],
              ];
            }
          }
        }
      }

      $expire = $this->time->getRequestTime() + self::CACHE_TTL;
      $this->cache->set($cid, $data, $expire, [self::ACTIVITY_FINDER_CACHE_TAG]);
    }

    return $data;
  }

  /**
   * Get Locations Info.
   */
  public function getLocationsInfo() {
    $data = [];
    $cid = 'openy_activity_finder:locations_info';
    $location_types = $this->config->get('location_types') ?? self::DEFAULT_LOCATION_TYPES;

    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      $nids = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->condition('type', $location_types, 'IN')
        ->condition('status', 1)
        ->sort('title', 'ASC')
        ->addTag('af_locations')
        ->accessCheck(FALSE)
        ->execute();
      $nids_chunked = array_chunk($nids, 20, TRUE);
      foreach ($nids_chunked as $chunked) {
        $locations = $this->entityTypeManager->getStorage('node')->loadMultiple($chunked);
        if (!empty($locations)) {
          foreach ($locations as $location) {
            $address = [];
            if (!empty($location->field_location_address->address_line1)) {
              array_push($address, $location->field_location_address->address_line1);
            }
            if (!empty($location->field_location_address->locality)) {
              array_push($address, $location->field_location_address->locality);
            }
            if (!empty($location->field_location_address->administrative_area)) {
              array_push($address, $location->field_location_address->administrative_area);
            }
            if (!empty($location->field_location_address->postal_code)) {
              array_push($address, $location->field_location_address->postal_code);
            }
            $address = implode(', ', $address);
            $days = [];
            if ($location->hasField('field_branch_hours')) {
              foreach ($location->field_branch_hours as $multi_hours) {
                $sub_hours = $multi_hours->getValue();
                $days = [
                  [
                    0 => "Mon - Fri:",
                    1 => $sub_hours['hours_mon'],
                  ],
                  [
                    0 => "Sat - Sun:",
                    1 => $sub_hours['hours_sat'],
                  ],
                ];
              }
            }
            $data[$location->label()] = [
              'type' => $location->bundle(),
              'address' => $address,
              'days' => $days,
              'email' => $location->field_location_email->value ?? '',
              'nid' => $location->id(),
              'phone' => $location->field_location_phone->value ?? '',
              'title' => $location->label(),
            ];
          }
        }
      }
      $expire = $this->time->getRequestTime() + self::CACHE_TTL;
      $this->cache->set($cid, $data, $expire, [self::ACTIVITY_FINDER_CACHE_TAG]);
    }

    return $data;
  }

  /**
   *
   */
  public function getCategoriesTopLevel() {
    $categories = [];
    $programInfo = $this->getCategoryProgramInfo();
    $exclude_nids = explode(',', $this->config->get('exclude'));

    foreach ($programInfo as $key => $item) {
      if (in_array($key, $exclude_nids)) {
        continue;
      }
      $categories[$item['program']['nid']] = $item['program']['title'];
    }
    return array_values($categories);
  }

  /**
   *
   */
  public function getCategories() {
    $categories = [];
    $programInfo = $this->getCategoryProgramInfo();
    $exclude_nids = explode(',', $this->config->get('exclude'));

    foreach ($programInfo as $key => $item) {
      if (in_array($key, $exclude_nids)) {
        continue;
      }
      $categories[$item['program']['nid']]['value'][] = [
        'value' => $key,
        'label' => $item['title'],
      ];
      $categories[$item['program']['nid']]['label'] = $item['program']['title'];
    }
    return array_values($categories);
  }

  /**
   * {@inheritdoc}
   */
  public function getLocations() {
    // Array with predefined keys for sorting in application location filters.
    // $locations = ['branch' => [], 'camp' => [], 'facility' => [], ...];
    $location_types = $this->config->get('location_types') ?? self::DEFAULT_LOCATION_TYPES;
    $locations = array_map(fn($value): array => [], array_filter($location_types));

    $locationsInfo = $this->getLocationsInfo();

    // Build a lookup array of content types and their labels.
    $content_types = array_map(fn($value): string => $value->label(), NodeType::loadMultiple());

    foreach ($locationsInfo as $key => $item) {
      $locations[$item['type']]['value'][] = [
        'value' => $item['nid'],
        'label' => $key,
      ];
      $locations[$item['type']]['label'] = $content_types[$item['type']];
    }
    return array_filter(array_values($locations));
  }

  /**
   * {@inheritdoc}
   */
  public function getProgramsMoreInfo($request) {
    // Idea is that when we use Solr backend we have all the data
    // available in runProgramSearch() call so this call is not needed
    // meanwhile you can alter search results to set availability_status
    // to be empty so getProgramsMoreInfo call will be triggered and you
    // can alter its behavior. For example if you like to check availability
    // with live call to your CRM.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getPages($count) {
    $pages = [];
    // Calculate number of pages.
    $pages_count = $count / $this::TOTAL_RESULTS_PER_PAGE;
    $pages_count = ceil($pages_count);
    $pages['total_pages'] = $pages_count;
    $range = range(1, $pages_count);
    // Make array starts from 1 for better usage.
    array_unshift($range, '');
    unset($range[0]);
    $pages['pages'] = $range;

    return $pages;
  }

  /**
   *
   */
  public function getSortOptions() {
    return [
      'title__ASC' => t('Sort by Title (A-Z)'),
      'title__DESC' => t('Sort by Title (Z-A)'),
      'field_session_location__ASC' => t('Sort by Location (A-Z)'),
      'field_session_location__DESC' => t('Sort by Location (Z-A)'),
      'class_title__ASC' => t('Sort by Activity (A-Z)'),
      'class_title__DESC' => t('Sort by Activity (Z-A)'),
      'af_time_of_day__ACS' => t('Sort by Start time (A-Z)'),
      'af_time_of_day__DESC' => t('Sort by Start time (Z-A)'),
      'af_date_of_day__ACS' => t('Sort by Nearest Date (A-Z)'),
      'af_date_of_day__DESC' => t('Sort by Furthest Date (Z-A)'),
      $this->getRelevanceSort() => t('Sort by Relevance'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevanceSort() {
    return 'search_api_relevance__DESC';
  }

  /**
   * Date months to years transformation.
   *
   * @param array $ages
   *   Array with min and max age values.
   *
   * @return string
   *   String with month or year.
   */
  public function convertData($ages = []) {
    $ages_y = [];
    for ($i = 0; $i < count($ages); $i++) {
      if ($ages[$i] > 18) {
        if ($ages[$i] % 12) {
          $ages_y[$i] = number_format($ages[$i] / 12, 1, '.', '');
        }
        else {
          $ages_y[$i] = number_format($ages[$i] / 12, 0, '.', '');
        }
        if (isset($ages[$i + 1]) && $ages[$i + 1] == 0) {
          $ages_y[$i] .= t('+ years');
        }
        if (isset($ages[$i + 1]) && $ages[$i + 1] > 18 || !isset($ages[$i + 1])) {
          if ($i % 2 || (!$ages[$i + 1]) && !($i % 2)) {
            $ages_y[$i] .= t(' years');
          }
        }
      }
      else {
        if ($ages[$i] <= 18 && $ages[$i] != 0) {
          $plus = '';
          if (isset($ages[$i + 1]) && $ages[$i + 1] == 0) {
            $plus = ' + ';
          }
          $ages_y[$i] = $ages[$i] . \Drupal::translation()->formatPlural($ages[$i], ' month', ' months' . $plus);
        }
        else {
          if (isset($ages[$i + 1]) && $ages[$i] == 0) {
            $ages_y[$i] = $ages[$i];
          }
        }
      }
    }
    return implode(' - ', $ages_y);
  }

  /**
   * Helper function to calculate weekday quantity in period.
   *
   * @param string $dayName
   *   eg 'Mon', 'Tue' etc.
   * @param \DateTimeInterface $start
   * @param \DateTimeInterface $end
   *
   * @return int
   *
   * @throws \Exception
   */
  public function countDaysByName($dayName, \DateTimeInterface $start, \DateTimeInterface $end) {
    $count = 0;
    $interval = new \DateInterval('P1D');
    $period = new \DatePeriod($start, $interval, $end);

    foreach ($period as $day) {
      if ($day->format('D') === ucfirst(substr($dayName, 0, 3))) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Get session data.
   *
   * @param array $session_ids
   *   Array session nids to get data.
   *
   * @return array
   *   Data array with structure similar to search results.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function getSessions($session_ids) {
    // Make a request to Search API to get sessions data.
    $results = $this->doSessionsSearchRequest($session_ids);
    // Set log_id to default value because user not run any search here.
    $log_id = 0;
    // Process results.
    return $this->processResults($results, $log_id);
  }

  /**
   * Get results for session search request.
   *
   * @param array $session_ids
   *   Array session nids to get data.
   *
   * @return \Drupal\search_api\Query\ResultSet
   *   Search results set.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  private function doSessionsSearchRequest($session_ids) {
    $index_id = $this->config->get('index') ? $this->config->get('index') : 'default';
    $index = Index::load($index_id);
    /** @var \Drupal\search_api\Query\Query $query */
    $query = $index->query();
    $parse_mode = \Drupal::service('plugin.manager.search_api.parse_mode')->createInstance('direct');
    $query->getParseMode()->setConjunction('OR');
    $query->setParseMode($parse_mode);
    $query->addCondition('status', 1);
    $query->addCondition('nid', $session_ids, 'IN');
    $query->accessCheck(FALSE);
    return $query->execute();
  }

}
