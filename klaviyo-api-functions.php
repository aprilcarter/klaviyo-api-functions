<?php
/**
 * All propritary and compromising information removed from functions
 */

 /**
  * Subscribe someone to a list in Klaviyo
  *
  * @param string $list_id Klaviyo's Itnernal identification string for the list (must be retrieved from the Klaviyo account)
  * @param array $profiles The data identifying the people who are being subscribed to the list with property data in the format array(array("email" => "...", "property_name" => "..."), ...)
  *
  * @see https://www.klaviyo.com/docs/api/v2/lists#post-subscribe For the accepted JSON structure for this endpoint
  *
  * @return string The result of API call
  */
function add_to_klaviyo_list($list_id = "", $profiles = array()) {
    $postfields = json_encode(array(
        "api_key" => KLAVIYO_API_KEY,
        "profiles" => $profiles
    ));

    $request_url = "https://a.klaviyo.com/api/v2/list/$list_id/subscribe";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $request_url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postfields))
    );

    if(!$result = curl_exec($ch)) {
        trigger_error(curl_error($ch));
    }

    curl_close($ch);
    return $result;
}

/**
 * Send any tracking info to Klaviyo
 *
 * @param mixed[] $data Identifying and action data for Klaviyo API
 *
 * @see https://www.klaviyo.com/docs/http-api#track For accepted JSON data structure
 */
function track_in_klaviyo($data) {
  	if(class_exists('WPKlaviyo')) {
		$default_data = array(
		    "token" => KLAVIYO_PUBLIC_KEY
		);

		$data = json_encode(array_merge($default_data, $data));
        $request_url = "https://a.klaviyo.com/api/track?data=" . base64_encode($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $request_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if(!curl_exec($ch)) {
            trigger_error(curl_error($ch));
        }

        curl_close($ch);
	}
}

/**
 * Get a person ID from their subscription record for a specific list
 *
 * As of 10/2018, there is no place to get a profile's internal Klaviyo ID from their email except from a subscription record. All other endpoints dealing with specific profiles only work with the Klaviyo's own internal unique ID for them, which may not always be known ahead of time. It is much easier to maintain a static group of list IDs than to maintain a local reference for person IDs
 *
 * @param string $email The email of the person for which the profile should be retrieved
 * @param string $list_id The internal Klaviyo ID for the list the person is subscribed to
 *
 * @return string|false The person ID or false if the ID could not be retrieved for any reason
 */
function get_klaviyo_person_id_from_list($email, $list_id) {
    $request_url = "https://a.klaviyo.com/api/v2/list/$list_id/subscribe?api_key=" . KLAVIYO_API_KEY . "&emails=$email";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $request_url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    if(!$response = curl_exec($ch)) {
        trigger_error(curl_error($ch));
    }

    curl_close($ch);

    $person_data = json_decode($response)[0];

    if(gettype($person_data) == "object" && property_exists($person_data, "id")) {
        return $person_data->id;
    }

    return false;
}

/**
 * Determines whether or not a specific action was recorded in Klaviyo for a user at least once
 *
 * Makes a call to the Klaviyo API to query a given person's timeline for instances of the given action or event, optionally filtered by a specific event properties
 *
 * @param string $email Customer's email
 * @param string $metric_id The metric id of the event you are looking for
 * @param mixed[] $properties The property IDs and values you are filtering by in the format array('PropertyName' => 'property_value')
 *
 * @return bool|null True if a request was submitted for the product, false if not, null if there was a problem with getting the metric data
 */
function user_action_recorded_in_klaviyo($email, $metric_id, $properties) {;
    $list_id = LIST_ID;
    $person_id = get_klaviyo_person_id_from_list($email, $list_id);
    $email_request_data = get_profile_metric_data($person_id, $metric_id);
    $product_name = strtolower($product_name);

    if(gettype($email_request_data) == "array") {
        if(empty($email_request_data)) {
            return false;
        }

        foreach($email_request_data as $instance) {
            if(property_exists($instance, "event_properties")) {
                $num_matching_props = 0;

                foreach($properties as $prop_name => $prop_val) {
                    if(property_exists($instance->event_properties, $prop_name)) {
                        $response_prop_val = $instance->event_properties->$prop_name;
                        if(strtolower($response_prop_val) == $prop_val) {
                            $num_matching_props += 1;
                        }
                    }
                }

                if ($num_matching_props == count($properties)) {
                    return true;
                }
            }
        }

        return false;
    }

    return null;
}

/**
 * Get or update properties for a given profile
 *
 * Determines whether to retrieve or update based on the presence of data
 *
 * @param string $person_id Klaviyo internal ID for the person to be retrieved or changed
 * @param mixed[] $data Data to update the person's record with. If left empty, the profile's data will be retrieved rather than updated.
 *
 * @return mixed[] The response object for either update or retrieval
 */
function get_update_klaviyo_profile_info($person_id, $data=array()) {
    $request_url = "https://a.klaviyo.com/api/v1/person/$person_id?api_key=" . KLAVIYO_API_KEY;

    $ch = curl_init();

    if(!empty($data)) {
        foreach($data as $key => $value) {
            $request_url .= "&$key=$value";
        }

        curl_setopt($ch, CURLOPT_PUT, 1);
    }

    curl_setopt($ch, CURLOPT_URL, $request_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    if (!$response = curl_exec($ch)) {
        trigger_error(curl_error($ch));
    }

    curl_close($ch);

    return json_decode($response);
}

/**
 * Get specific timeline event data for a specfic person from Klaviyo
 *
 * @param string $person_id Internal Klaviyo ID for the profile of the person whose timeline is being inspected
 * @param string $metric_id Internal Klaviyo ID for the metric
 *
 * @return mixed[]|null|false Array (may be empty) on success, null on request success but no data retrieval, false on error
 */
function get_profile_metric_data($person_id, $metric_id) {
    $request_url = "https://a.klaviyo.com/api/v1/person/$person_id/metric/$metric_id/timeline?api_key=". KLAVIYO_API_KEY;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $request_url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    if (!$response = curl_exec($ch)) {
        trigger_error(curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($response);

    if(gettype($data) == "object") {
        if(property_exists($data, "data")) {
            //empty array means they don't have this metric in their timeline
            return $data->data;
        } else {
            //indicates there was data returned, but not the data we want (includes API errors)
            return null;
        }
    }

    //indicates there was an error with the request itself, outside of the API
    return false;
}
