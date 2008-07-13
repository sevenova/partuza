<?php
/*
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements. See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership. The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License. You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied. See the License for the
 * specific language governing permissions and limitations under the License.
 * 
 */

class PartuzaDbFetcher {
	private $db;
	private $url_prefix;
	private $cache;
	
	// Singleton
	private static $fetcher;


	private function connectDb()
	{
		//TODO move these to PartuzaConfig.php, this is uglaaaaaayyyy!
		// enter your db config here
		$this->db = mysqli_connect('localhost', 'root', '', 'partuza');
		mysqli_select_db($this->db, 'partuza');
	}
	
	private function __construct()
	{
		$cache = Config::get('data_cache');
		$this->cache = new $cache();
		// change this to your site's location
		$this->url_prefix = 'http://www.partuza.nl';
	}
	
	private function checkDb()
	{
		if (!is_resource($this->db)) {
			$this->connectDb();
		}
	}
	
	private function __clone()
	{
		// private, don't allow cloning of a singleton
	}

	static function get()
	{
		// This object is a singleton
		if (! isset(PartuzaDbFetcher::$fetcher)) {
			PartuzaDbFetcher::$fetcher = new PartuzaDbFetcher();
		}
		return PartuzaDbFetcher::$fetcher;
	}

	public function createActivity($person_id, $activity, $app_id = '0')
	{
		$this->checkDb();
		$app_id = mysqli_real_escape_string($this->db, $app_id);
		$person_id = mysqli_real_escape_string($this->db, $person_id);
		$title = isset($activity->title) ? trim($activity->title) : (@isset($activity['fields_']['title']) ? $activity['fields_']['title'] : '');
		$body = isset($activity->body) ? trim($activity->body) : (@isset($activity['fields_']['body']) ? $activity['fields_']['body'] : '');
		$title = mysqli_real_escape_string($this->db, $title);
		$body = mysqli_real_escape_string($this->db, $body);
		$time = time();
		mysqli_query($this->db, "insert into activities (id, person_id, app_id, title, body, created) values (0, $person_id, $app_id, '$title', '$body', $time)");
		if (! ($activityId = mysqli_insert_id($this->db))) {
			return false;
		}
		$mediaItems = isset($activity->mediaItems) ? $activity->mediaItems : (isset($activity['fields_']['mediaItems']) ? $activity['fields_']['mediaItems'] : array());
		if (count($mediaItems)) {
			foreach ($mediaItems as $mediaItem) {
				$type = isset($mediaItem->type) ? $mediaItem->type : (isset($mediaItem['fields_']['type']) ? $mediaItem['fields_']['type'] : '');
				$mimeType = isset($mediaItem->mimeType) ? $mediaItem->type : (isset($mediaItem['fields_']['mimeType']) ? $mediaItem['fields_']['mimeType'] : '');
				$url = isset($mediaItem->url) ? $mediaItem->url : (isset($mediaItem['fields_']['url']) ? $mediaItem['fields_']['url'] : '');
				$type = mysqli_real_escape_string($this->db, trim($type));
				$mimeType = mysqli_real_escape_string($this->db, trim($mimeType));
				$url = mysqli_real_escape_string($this->db, trim($url));
				mysqli_query($this->db, "insert into activity_media_items (id, activity_id, mime_type, media_type, url) values (0, $activityId, '$mimeType', '$type', '$url')");
				if (! mysqli_insert_id($this->db)) {
					return false;
				}
			}
		}
		$this->invalidate_dependency('activities', $person_id);
		return true;
	}

	public function load_getActivities($ids, $first = false, $max = false)
	{
		$this->checkDb();
		$activities = array();
		foreach ($ids as $key => $val) {
			$ids[$key] = mysqli_real_escape_string($this->db, $val);
			$this->add_dependency('activities', $ids[$key]);
		}
		/*
		$res = mysqli_query($this->db, "select count(*) from  activities where activities.person_id in (" . implode(',', $ids) . ") order by created desc");
		$count = 0;
		if ($res && mysqli_num_rows($res)) {
			list($count) = mysqli_fetch_row($res);
		}
		//$activities['totalSize'] = $count;
		*/
		$query = "
			select 
				activities.person_id as person_id,
				activities.id as activity_id,
				activities.title as activity_title,
				activities.body as activity_body,
				activities.created as created
			from 
				activities
			where
				activities.person_id in (" . implode(',', $ids) . ")
			order by 
				created desc
			";
		if ($first !== false && $max !== false && is_numeric($first) && is_numeric($max) && $first >= 0 && $max > 0) {
			$query .= " limit $first, $max";
		}
		$res = mysqli_query($this->db, $query);
		while ($row = @mysqli_fetch_array($res, MYSQLI_ASSOC)) {
			$activity = new Activity($row['activity_id'], $row['person_id']);
			$activity->setStreamTitle('activities');
			$activity->setTitle($row['activity_title']);
			$activity->setBody($row['activity_body']);
			$activity->setPostedTime($row['created']);
			$activity->setMediaItems($this->getMediaItems($row['activity_id']));
			$activities[] = $activity;
		}
		return $activities;
	}

	private function getMediaItems($activity_id)
	{
		$media = array();
		$activity_id = mysqli_real_escape_string($this->db, $activity_id);
		$res = mysqli_query($this->db, "select mime_type, media_type, url from activity_media_items where activity_id = $activity_id");
		while (list($mime_type, $type, $url) = @mysqli_fetch_row($res)) {
			$media[] = new MediaItem($mime_type, $type, $url);
		}
		return $media;
	}

	public function load_getFriendIds($person_id)
	{
		$this->checkDb();
		$this->add_dependency('people', $person_id);
		$ret = array();
		$person_id = mysqli_real_escape_string($this->db, $person_id);
		$res = mysqli_query($this->db, "select person_id, friend_id from friends where person_id = $person_id or friend_id = $person_id");
		while (list($pid, $fid) = @mysqli_fetch_row($res)) {
			$id = ($pid == $person_id) ? $fid : $pid;
			$this->add_dependency('people', $id);
			$ret[] = $id;
		}
		return $ret;
	}

	public function setAppData($person_id, $key, $value, $app_id)
	{
		$this->checkDb();
		$person_id = mysqli_real_escape_string($this->db, $person_id);
		$key = mysqli_real_escape_string($this->db, $key);
		$value = mysqli_real_escape_string($this->db, $value);
		$app_id = mysqli_real_escape_string($this->db, $app_id);
		if (empty($value)) {
			// orkut specific type feature, empty string = delete value
			if (! @mysqli_query($this->db, "delete from application_settings where application_id = $app_id and person_id = $person_id and name = $key")) {
				return false;
			}
		} else {
			if (! @mysqli_query($this->db, "insert into application_settings (application_id, person_id, name, value) values ($app_id, $person_id, '$key', '$value') on duplicate key update value = '$value'")) {
				echo "error: ".mysqli_error($this->db);
				return false;
			}
		}
		$this->invalidate_dependency('person_application_prefs', $person_id);
		return true;
	}

	public function load_getAppData($ids, $keys, $app_id)
	{
		$this->checkDb();
		$data = array();
		foreach ($ids as $key => $val) {
			$ids[$key] = mysqli_real_escape_string($this->db, $val);
		}
		if (!isset($keys[0])) {
			$keys[0] = '*';
		}
		if ($keys[0] == '*') {
			$keys = '';
		} else {
			foreach ($keys as $key => $val) {
				$keys[$key] = "'" . mysqli_real_escape_string($this->db, $val) . "'";
			}
			$keys = "and name in (" . implode(',', $keys) . ")";
		}
		$res = mysqli_query($this->db, "select person_id, name, value from application_settings where application_id = $app_id and person_id in (" . implode(',', $ids) . ") $keys");
		while (list($person_id, $key, $value) = @mysqli_fetch_row($res)) {
			$this->add_dependency('person_application_prefs', $person_id);
			if (! isset($data[$person_id])) {
				$data[$person_id] = array();
			}
			$data[$person_id][$key] = $value;
		}
		return $data;
	}
	
	public function load_getPeople($ids, $profileDetails, $first = false, $max = false)
	{
		$this->checkDb();
		$ret = array();
		$res = mysqli_query($this->db, "select count(*) from persons where id in (" . implode(',', $ids) . ")");
		list($count) = mysqli_fetch_row($res);
		$ret['totalSize'] = $count;
		
		$query = "select * from persons where id in (" . implode(',', $ids) . ") order by id ";
		if ($first !== false && $max !== false && is_numeric($first) && is_numeric($max) && $first >= 0 && $max > 0) {
			$query .= " limit $first, $max";
		}
		$res = mysqli_query($this->db, $query);
		if ($res) {
			while ($row = @mysqli_fetch_array($res, MYSQLI_ASSOC)) {
				$this->add_dependency('people', $row['id']);
				$person_id = mysqli_real_escape_string($this->db, $row['id']);
				$name = new Name($row['first_name'] . ' ' . $row['last_name']);
				$name->setGivenName($row['first_name']);
				$name->setFamilyName($row['last_name']);
				$person = new Person($row['id'], $name);
				$person->setAboutMe($row['about_me']);
				$person->setAge($row['age']);
				$person->setChildren($row['children']);
				$person->setDateOfBirth($row['date_of_birth']);
				$person->setEthnicity($row['ethnicity']);
				$person->setFashion($row['fashion']);
				$person->setHappiestWhen($row['happiest_when']);
				$person->setHumor($row['humor']);
				$person->setJobInterests($row['job_interests']);
				$person->setLivingArrangement($row['living_arrangement']);
				$person->setLookingFor($row['looking_for']);
				$person->setNickname($row['nickname']);
				$person->setPets($row['pets']);
				$person->setPoliticalViews($row['political_views']);
				$person->setProfileSong($row['profile_song']);
				$person->setProfileUrl($this->url_prefix . '/profile/' . $row['id']);
				$person->setProfileVideo($row['profile_video']);
				$person->setRelationshipStatus($row['relationship_status']);
				$person->setReligion($row['religion']);
				$person->setRomance($row['romance']);
				$person->setScaredOf($row['scared_of']);
				$person->setSexualOrientation($row['sexual_orientation']);
				$person->setStatus($row['status']);
				$person->setThumbnailUrl(! empty($row['thumbnail_url']) ? $this->url_prefix . $row['thumbnail_url'] : '');
				$person->setTimeZone($row['time_zone']);
				if (! empty($row['drinker'])) {
					$person->setDrinker($row['drinker']);
				}
				if (! empty($row['gender'])) {
					$person->setGender($row['gender']);
				}
				if (! empty($row['smoker'])) {
					$person->setSmoker($row['smoker']);
				}
				/* the following fields require additional queries so are only executed if requested */
				if (isset($profileDetails['activities'])) {
					$activities = array();
					$res2 = mysqli_query($this->db, "select activity from person_activities where person_id = " . $person_id);
					while (list($activity) = @mysqli_fetch_row($res2)) {
						$activities[] = $activity;
					}
					$person->setActivities($activities);
				}
				if (isset($profileDetails['addresses'])) {
					$addresses = array();
					$res2 = mysqli_query($this->db, "select address.* from person_addresses, addresses where address.id = person_addresses.address_id and person_addresses.person_id = " . $person_id);
					while ($row = mysqli_fetch_array($res2, MYSQLI_ASSOC)) {
						if (empty($row['unstructured_address'])) {
							$row['unstructured_address'] = trim($row['street_address'] . " " . $row['region'] . " " . $row['country']);
						}
						$addres = new Address($row['unstructured_address']);
						$addres->setCountry($row['country']);
						$addres->setExtendedAddress($row['extended_address']);
						$addres->setLatitude($row['latitude']);
						$addres->setLongitude($row['longitude']);
						$addres->setLocality($row['locality']);
						$addres->setPoBox($row['po_box']);
						$addres->setPostalCode($row['postal_code']);
						$addres->setRegion($row['region']);
						$addres->setStreetAddress($row['street_address']);
						$addres->setType($row['address_type']);
						$addresses[] = $addres;
					}
					$person->setAddresses($addresses);
				}
				if (isset($profileDetails['bodyType'])) {
					$res2 = mysqli_query($this->db, "select * from person_body_type where person_id = " . $person_id);
					if (mysqli_num_rows($res2)) {
						$row = mysql_fetch_array($res2, MYSQLI_ASSOC);
						$bodyType = new BodyType();
						$bodyType->setBuild($row['build']);
						$bodyType->setEyeColor($row['eye_color']);
						$bodyType->setHairColor($row['hair_color']);
						$bodyType->setHeight($row['height']);
						$bodyType->setWeight($row['weight']);
						$person->setBodyType($bodyType);
					}
				}
				if (isset($profileDetails['books'])) {
					$books = array();
					$res2 = mysqli_query($this->db, "select book from person_books where person_id = " . $person_id);
					while (list($book) = @mysqli_fetch_row($res2)) {
						$books[] = $book;
					}
					$person->setBooks($books);
				}
				if (isset($profileDetails['cars'])) {
					$cars = array();
					$res2 = mysqli_query($this->db, "select car from person_cars where person_id = " . $person_id);
					while (list($car) = @mysqli_fetch_row($res2)) {
						$cars[] = $car;
					}
					$person->setCars($cars);
				}
				if (isset($profileDetails['currentLocation'])) {
					$addresses = array();
					$res2 = mysqli_query($this->db, "select address.* from person_current_location, addresses where address.id = person_current_location.address_id and person_addresses.person_id = " . $person_id);
					if (mysqli_num_rows($res2)) {
						$row = mysqli_fetch_array($res2, MYSQLI_ASSOC);
						if (empty($row['unstructured_address'])) {
							$row['unstructured_address'] = trim($row['street_address'] . " " . $row['region'] . " " . $row['country']);
						}
						$addres = new Address($row['unstructured_address']);
						$addres->setCountry($row['country']);
						$addres->setExtendedAddress($row['extended_address']);
						$addres->setLatitude($row['latitude']);
						$addres->setLongitude($row['longitude']);
						$addres->setLocality($row['locality']);
						$addres->setPoBox($row['po_box']);
						$addres->setPostalCode($row['postal_code']);
						$addres->setRegion($row['region']);
						$addres->setStreetAddress($row['street_address']);
						$addres->setType($row['address_type']);
						$person->setCurrentLocation($addres);
					}
				}
				if (isset($profileDetails['emails'])) {
					$emails = array();
					$res2 = mysqli_query($this->db, "select address, email_type from person_emails where person_id = " . $person_id);
					while (list($address, $type) = @mysqli_fetch_row($res2)) {
						$emails[] = new Email($address, $type);
					}
					$person->setEmails($emails);
				}
				if (isset($profileDetails['food'])) {
					$foods = array();
					$res2 = mysqli_query($this->db, "select food from person_foods where person_id = " . $person_id);
					while (list($food) = @mysqli_fetch_row($res2)) {
						$foods[] = $food;
					}
					$person->setFood($foods);
				}
				
				if (isset($profileDetails['heroes'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select hero from person_heroes where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setHeroes($strings);
				}
				
				if (isset($profileDetails['interests'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select interest from person_interests where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setInterests($strings);
				}
				if (isset($profileDetails['jobs'])) {
					$organizations = array();
					$res2 = mysqli_query($this->db, "select organizations.* from person_jobs, organizations where organizations.id = person_jobs.organization_id and person_jobs.person_id = " . $person_id);
					while ($row = mysqli_fetch_array($res2, MYSQLI_ASSOC)) {
						$organization = new Organization();
						$organization->setDescription($row['description']);
						$organization->setEndDate($row['end_date']);
						$organization->setField($row['field']);
						$organization->setName($row['name']);
						$organization->setSalary($row['salary']);
						$organization->setStartDate($row['start_date']);
						$organization->setSubField($row['sub_field']);
						$organization->setTitle($row['title']);
						$organization->setWebpage($row['webpage']);
						if ($row['address_id']) {
							$res3 = mysqli_query($this->db, "select * from addresses where id = " . mysqli_real_escape_string($this->db, $row['address_id']));
							if (mysqli_num_rows($res3)) {
								$row = mysqli_fetch_array($res3, MYSQLI_ASSOC);
								if (empty($row['unstructured_address'])) {
									$row['unstructured_address'] = trim($row['street_address'] . " " . $row['region'] . " " . $row['country']);
								}
								$addres = new Address($row['unstructured_address']);
								$addres->setCountry($row['country']);
								$addres->setExtendedAddress($row['extended_address']);
								$addres->setLatitude($row['latitude']);
								$addres->setLongitude($row['longitude']);
								$addres->setLocality($row['locality']);
								$addres->setPoBox($row['po_box']);
								$addres->setPostalCode($row['postal_code']);
								$addres->setRegion($row['region']);
								$addres->setStreetAddress($row['street_address']);
								$addres->setType($row['address_type']);
								$organization->setAddress($address);
							}
						}
						$organizations[] = $organization;
					}
					$person->setJobs($organizations);
				}
				//TODO languagesSpoken, currently missing the languages / countries tables so can't do this yet

				if (isset($profileDetails['movies'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select movie from person_movies where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setMovies($strings);
				}
				if (isset($profileDetails['music'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select music from person_music where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setMusic($strings);
				}
				if (isset($profileDetails['phoneNumbers'])) {
					$numbers = array();
					$res2 = mysqli_query($this->db, "select number, number_type from person_phone_numbers where person_id = " . $person_id);
					while (list($number, $type) = @mysqli_fetch_row($res2)) {
						$numbers[] = new Phone($number, $type);
					}
					$person->setPhoneNumbers($numbers);
				}
				if (isset($profileDetails['quotes'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select quote from person_quotes where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setQuotes($strings);
				}
				if (isset($profileDetails['schools'])) {
					$organizations = array();
					$res2 = mysqli_query($this->db, "select organizations.* from person_schools, organizations where organizations.id = person_schools.organization_id and person_schools.person_id = " . $person_id);
					while ($row = mysqli_fetch_array($res2, MYSQLI_ASSOC)) {
						$organization = new Organization();
						$organization->setDescription($row['description']);
						$organization->setEndDate($row['end_date']);
						$organization->setField($row['field']);
						$organization->setName($row['name']);
						$organization->setSalary($row['salary']);
						$organization->setStartDate($row['start_date']);
						$organization->setSubField($row['sub_field']);
						$organization->setTitle($row['title']);
						$organization->setWebpage($row['webpage']);
						if ($row['address_id']) {
							$res3 = mysqli_query($this->db, "select * from addresses where id = " . mysqli_real_escape_string($this->db, $row['address_id']));
							if (mysqli_num_rows($res3)) {
								$row = mysqli_fetch_array($res3, MYSQLI_ASSOC);
								if (empty($row['unstructured_address'])) {
									$row['unstructured_address'] = trim($row['street_address'] . " " . $row['region'] . " " . $row['country']);
								}
								$addres = new Address($row['unstructured_address']);
								$addres->setCountry($row['country']);
								$addres->setExtendedAddress($row['extended_address']);
								$addres->setLatitude($row['latitude']);
								$addres->setLongitude($row['longitude']);
								$addres->setLocality($row['locality']);
								$addres->setPoBox($row['po_box']);
								$addres->setPostalCode($row['postal_code']);
								$addres->setRegion($row['region']);
								$addres->setStreetAddress($row['street_address']);
								$addres->setType($row['address_type']);
								$organization->setAddress($address);
							}
						}
						$organizations[] = $organization;
					}
					$person->setSchools($organizations);
				}
				if (isset($profileDetails['sports'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select sport from person_sports where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setSports($strings);
				}
				if (isset($profileDetails['tags'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select tag from person_tags where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setTags($strings);
				}
				
				if (isset($profileDetails['turnOns'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select turn_on from person_turn_ons where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setTurnOns($strings);
				}
				if (isset($profileDetails['turnOffs'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select turn_off from person_turn_offs where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setTurnOffs($strings);
				}
				if (isset($profileDetails['urls'])) {
					$strings = array();
					$res2 = mysqli_query($this->db, "select url from person_urls where person_id = " . $person_id);
					while (list($data) = @mysqli_fetch_row($res2)) {
						$strings[] = $data;
					}
					$person->setUrls($strings);
				}
				$ret[$person_id] = $person;
			}
		}
		return $ret;
	}
	
	//FIXME this code has been lifted from partuza/Library/Model.php, but should really
	// restructure things a bit so that partuza and the shindig data adapters use the same
	// models, and utility classes. Getting it working is step1, reworing the structure
	// will be step 2 :-)
	// Do note that this does work fine, since partuza and the partuza shindig code
	// use the same caching name space ('people', $person_id) so an invalidate on one
	// end should also modify it on the other end. (as long as they use the same cache source)
	
	// A local scope cache per model, every cache hit is stored in 
	// here so the next request doesn't have to fetch it
	private $local_cache = array();
	// A LIFO call stack to trace recursive dependencies
	private $call_stack = array();
	// The dependency maps (loaded on the demand, aka lazy loading)
	private $dep_map = array();
	// Model Classes override this and list the function names that can be cached
	// if a function name is not in this array, it won't be cached
	// (useful for things that would be inefficient to cache like searches etc)
	public  $cachable = array(
		'getActivities',
		'getFriendIds',
		'getAppData',
		'getPeople'
	);
	
	public function __destruct()
	{
		// dep_map holds only modified entries, so store each entry
		foreach ($this->dep_map as $key => $new_deps) {
			// retrieve the most uptodate map and merge them with our results
			if (($existing_deps = $this->cache->get($key)) !== false) {
				foreach ($existing_deps as $existing_dep) {
					if (!in_array($existing_dep, $new_deps)) {
						$new_deps[] = $existing_dep;
					}
				}
			}
			$this->cache->set(md5($key), $new_deps);
		}
	}
	
	/**
	 * Invalidate (remove) the cache a certain type-id relationship
	 *
	 * @param string $type the type (ie 'people')
	 * @param string $id the ID of this entity
	 */
	public function invalidate_dependency($type, $id)
	{
		$key = $type.'_deps:'.$id;
		if (($data = $this->cache->get(md5($key))) !== false) {
			try {
				$this->cache->delete($key);
			} catch (CacheException $e) {}
			foreach ($data as $dep) {
				try {
					$this->cache->delete($dep);
				} catch (CacheException $e) {} 
			}
		}
	}
	
	/**
	 * Adds a dependency to your dependency chain, call this using $type = 'data_type', $id = id of the entity:
	 *	$this->add_dependency('people', '$user_id);
	 * Remember to call this multiple times if multiple id's are involved:
	 * function load_friends() {
	 * 	//.. get friends from db
	 * 	foreach ($friends as $id) {
	 * 		$this->add_dependency('people', $id);
	 * 	}
	 * @param string $type the data type, all dep checking is done within it's own type (ie: 'people')
	 * @param string $id the ID of this entity, ie '1'
	 */
	public function add_dependency($type, $id)
	{
		$key = $type.'_deps:'.$id;
		// only load the dep map once per key, lazy loading style
		if (!isset($this->dep_map[$key])) {
			if (($deps = $this->cache->get(md5($key))) !== false) {
				$this->dep_map[$key] = $deps;
			} else {
				$this->dep_map[$key] = array();
			}
		}
		// add depedency relationship for the entire call stack (catches recursive dependencies)
		foreach ($this->call_stack as $request) {
			if (!in_array($request, $this->dep_map[$key])) {
				$this->dep_map[$key][] = $request;
			}
		}
	}
	
	/**
	 * Returns the current top level request
	 *
	 * @return string request id
	 */
	private function current_request()
	{
		return $this->call_stack[count($this->call_stack) - 1];
	}

	/**
	 * Adds a request to the stack
	 *
	 * @param string $key the __call key which is the md5 of the method + its params
	 */
	private function push_request($key)
	{
		$this->call_stack[count($this->call_stack)] = $key;
	}
	
	/**
	 * Removes the most recent request from the top of the stack
	 *
	 */
	private function pop_request()
	{
		unset($this->call_stack[count($this->call_stack) - 1]);
	}
	
	/**
	 * Magic __call function that is called for each unknown function, which checks if
	 * load_{$method_name} exists, and wraps caching around it
	 * 
	 *
	 * @param string $method method name
	 * @param array $arguments arguments (argv) array
	 * @return unknown data returned from cache, or from load_{$method_name} function
	 */
	public function __call($method, $arguments)
	{
		$key = md5($method.serialize($arguments));
		// prevent double-loading of data
		if (isset($this->local_cache[$key])) {
			return $this->local_cache[$key];
		}
		if (in_array($method, $this->cachable)) {
			$data = $this->cache->get($key);
			if ($data !== false) {
				return $data;
			} else {
				$function  = "load_{$method}";
				if (is_callable(array($this, $function))) {
					// cache operations might call other cache operations again, so for dep tracking we use a LIFO call stack
					$this->push_request($key);
					$data = call_user_func_array(array($this, $function), $arguments);
					$this->cache->set($key, $data);
					$this->local_cache[$key] = $data;
					$this->pop_request();
					return $data;
				} else {
					throw new Exception("Invalid method: load_{$method}");
				}
			}
		} else {
			// non cachable information, always do a plain load
			$function  = "load_{$method}";
			if (is_callable(array($this, $function))) {
				$data = call_user_func_array(array($this, $function), $arguments);
				$this->local_cache[$key] = $data;
				return $data;
			} else {
				throw new Exception("Invalid method: load_{$method}");
			}
		}
		return false;
	}

}
