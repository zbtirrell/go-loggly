<?php

class GO_Loggly
{
	private $config = NULL;

	public function __construct()
	{
	}//end __construct

	/**
	 * get the config values or value
	 *
	 * @param string $key if set then return the config value for this key
	 * @return mixed the named config value or all the config values
	 */
	public function config( $key = NULL )
	{
		if ( ! $this->config )
		{
			$this->config = apply_filters( 'go_config', array(), 'go-loggly' );
		}

		if ( ! empty( $key ) )
		{
			return isset( $this->config[ $key ] ) ? $this->config[ $key ] : FALSE;
		}

		return $this->config;
	}//end config

	/**
	 * Write a log entry to Loggly
	 *
	 * @param array $input_string_or_array_or_object Data to be posted
	 * @param string $tags_string_or_array Tags (can be string or array, which results in comma-separated tags) under which Loggly will classify this entry
	 * @return Array of results including HTTP headers or WP_Error if the request failed. See codex for wp_remote_post for the response array format.
	 */
	public function inputs( $input_string_or_array_or_object, $tags_string_or_array = 'go-loggly' )
	{
		// check data
		if ( ! $input_string_or_array_or_object )
		{
			return new WP_Error( 'go-loggly_inputs_error', 'No message to log' );
		}

		// craft end-point, format: http://logs-01.loggly.com/inputs/TOKEN/tag/TAGS/, see https://www.loggly.com/docs/http-endpoint/
		$url = sprintf(
			'http://%s.loggly.com/inputs/%s/tag/%s',
			$this->config( 'inputs_subdomain' ),
			$this->config( 'customer_token' ),
			implode( ',', (array) $tags_string_or_array )
		);

		$response = wp_remote_post(
			$url,
			is_array( $input_string_or_array_or_object ) || is_object( $input_string_or_array_or_object )
			? array( 'body' => json_encode( $input_string_or_array_or_object ) )
			: array( 'body' => json_encode( array( 'message' => $input_string_or_array_or_object ) ) )
		);

		return $response;
	}//end inputs

	/**
	 * Search the logs previously captured on Loggly
	 *
	 * @param array $query_string_or_args_array
	 *  - See Search and Events Endpoint at https://www.loggly.com/docs/api-retrieving-data/
	 *  - Clients using this function need only specify the search query... fetching any data found is handled internally by the GO_Loggly_Search_Results_Pager
	 *  - Note: the events fetch REST api provides 3 optional arguments: 'page', 'format' and 'columns'...
	 *     We handle 'page' internally, and always use json format. Further, the 'columns' endpoint only works if format=csv,
	 *     so we currently do not support 'columns' for Events Endpoint (fetch query) targeting.
	 * @return GO_Loggly_Search_Results_Pager object (in the case of search returned some data) or WP_Error.
	 *  - See codex for wp_remote_post for the response array format.
	 */
	public function search( $query_string_or_args_array )
	{
		// check data
		if ( ! $query_string_or_args_array )
		{
			return new WP_Error( 'go-loggly_inputs_error', 'No search query' );
		}//end if

		// Craft end-point, e.g.,:
		//   curl -u <username>:<password> "http://<account>.loggly.com/apiv2/search?q=*,
		// Note: we are not going to support 'fields' for now, just 'search' . . .
		// see:
		//   https://www.loggly.com/docs/api-retrieving-data/

		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->config( 'username' ) . ':' . $this->config( 'password' ) ),
			)
		);

		if ( is_string( $query_string_or_args_array ) )
		{
			$query_string_or_args_array = array( 'q' => $query_string_or_args_array );
		}//end if

		if ( ! is_array( $query_string_or_args_array ) )
		{
			return new WP_Error( 'go-loggly_inputs_error', 'Invalid search input' );
		}// end if

		// Handle passed in Search Endpoint Parameters
		$url = sprintf(
			'http://%s.loggly.com/apiv2/search',
			$this->config( 'account' )
		);

		$url = add_query_arg( $query_string_or_args_array, $url );

		// do the search
		$search_response = wp_remote_get( $url, $args );
		if ( is_wp_error( $search_response ) )
		{
			return $search_response;
		}//end if

		// do the fetch if valid response
		if ( is_array( $search_response ) && '200' != $search_response['response']['code'] )
		{
			// in this case there is a valid HTTP Response but it's not a 200
			// so we just return it as an error, passing the response message as the WP_Error message, and the entire response as data
			return new WP_Error( 'GO_Loggly_Search_Error', $search_response['message'], $search_response );
		}//end if

		// check for expected format
		if ( ! $json_response = json_decode( $search_response['body'] ) )
		{
			return new WP_Error( 'GO_Loggly_JSON_Response_Missing', 'Search result did not contain Loggly JSON data. Cannot fetch records.' );
		}//end if

		// finally, make sure the RSID available
		if ( ! $json_response->rsid )
		{
			return new WP_Error( 'GO_Loggly_RSID_Missing', 'Search result did not contain Loggly RSID. Cannot fetch records.' );
		}//end if

		// craft end-point, format: curl -u <username>:<password> "http://<account>.loggly.com/apiv2/event?rsid=ID"
		$url = sprintf(
			'http://%s.loggly.com/apiv2/events?rsid=%s',
			$this->config( 'account' ),
			$json_response->rsid->id
		);

		// instantiate the search pager / iterator
		require_once __DIR__ . '/class-go-loggly-search-results-pager.php';
		$fetch_response_pager = new GO_Loggly_Search_Results_Pager( $url, $args );

		return $fetch_response_pager;
	}//end search
}//end class