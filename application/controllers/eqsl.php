<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class eqsl extends CI_Controller {

	/* Controls who can access the controller and its functions */
	function __construct()
	{
		parent::__construct();
		$this->load->helper(array('form', 'url'));
		
		$this->load->model('user_model');
		if(!$this->user_model->authorize(2)) { $this->session->set_flashdata('notice', 'You\'re not allowed to do that!'); redirect('dashboard'); }
	}
	
	private function loadFromFile($filepath)
	{
		// Figure out how we should be marking QSLs confirmed via eQSL
		$query = $query = $this->db->query('SELECT eqsl_rcvd_mark FROM config');
		$q = $query->row();
		$config['eqsl_rcvd_mark'] = $q->eqsl_rcvd_mark;
	
		ini_set('memory_limit', '-1');
		set_time_limit(0);

		$this->load->library('adif_parser');

		$this->adif_parser->load_from_file($filepath);

		$this->adif_parser->initialize();

		$table = "<table>";

		while($record = $this->adif_parser->get_record())
		{
			if(count($record) == 0)
			{
				break;
			};
	
			$time_on = date('Y-m-d', strtotime($record['qso_date'])) ." ".date('H:i', strtotime($record['time_on']));
			
			// The report from eQSL should only contain entries that have been confirmed via eQSL
			// If there's a match for the QSO from the report in our log, it's confirmed via eQSL.
			
			// If we have a positive match from LoTW, record it in the DB according to the user's preferences
			if ($record['qsl_sent'] == "Y")
			{
				$record['qsl_sent'] = $config['eqsl_rcvd_mark'];
			}
			
			$status = $this->logbook_model->import_check($time_on, $record['call'], $record['band']);
			if ($status == "Found")
			{
				$dupe = $this->logbook_model->eqsl_dupe_check($time_on, $record['call'], $record['band'], $config['eqsl_rcvd_mark']);
				if ($dupe == false)
				{
					$eqsl_status = $this->logbook_model->eqsl_update($time_on, $record['call'], $record['band'], $config['eqsl_rcvd_mark']);
				}
				else
				{
					$eqsl_status = "Already received an eQSL for this QSO.";
				}
			}
			else
			{
				$eqsl_status = "QSO not found";
			}
			$table .= "<tr>";
				$table .= "<td>".$time_on."</td>";
				$table .= "<td>".$record['call']."</td>";
				$table .= "<td>".$record['mode']."</td>";
				$table .= "<td>QSO Record: ".$status."</td>";
				$table .= "<td>eQSL Record: ".$eqsl_status."</td>";
			$table .= "<tr>";
		};

		$table .= "</table>";

		unlink($filepath);

		$data['eqsl_table'] = $table;

		$data['page_title'] = "eQSL Import Information";
		$this->load->view('layout/header', $data);
		$this->load->view('eqsl/analysis');
		$this->load->view('layout/footer');
	}

	public function import() {	
		$data['page_title'] = "eQSL Import";

		$config['upload_path'] = './uploads/';
		$config['allowed_types'] = 'adi|ADI';
		
		$this->load->library('upload', $config);
		
		$this->load->model('logbook_model');
		
		if ($this->input->post('eqslimport') == 'fetch')
		{			
			$file = $config['upload_path'] . 'eqslreport_download.adi';
			
			// Get credentials for eQSL
			$query = $this->user_model->get_by_id($this->session->userdata('user_id'));
    		$q = $query->row();
    		$data['user_eqsl_name'] = $q->user_eqsl_name;
			$data['user_eqsl_password'] = $q->user_eqsl_password;
			
			// Get URL for downloading the eqsl.cc inbox
			$query = $query = $this->db->query('SELECT eqsl_download_url FROM config');
			$q = $query->row();
			$eqsl_url = $q->eqsl_download_url;
			
			// Validate that LoTW credentials are not empty
			if ($data['user_eqsl_name'] == '' || $data['user_eqsl_password'] == '')
			{
				$this->session->set_flashdata('warning', 'You have not defined your eQSL.cc credentials!'); redirect('eqsl/import');
			}
			
			// Query the logbook to determine when the last LoTW confirmation was
			$eqsl_last_qsl_date = $this->logbook_model->eqsl_last_qsl_rcvd_date();
			
			// Build URL for eQSL inbox file
			$eqsl_url .= "?";
			$eqsl_url .= "UserName=" . $data['user_eqsl_name'];
			$eqsl_url .= "&Password=" . $data['user_eqsl_password'];
			
			$eqsl_url .= "&RcvdSince=" . $eqsl_last_qsl_date;
			
			// Pull back only confirmations
			$eqsl_url .= "&ConfirmedOnly=1";
			
 			// At this point, what we get isn't the ADI file we need, but rather
			// an HTML page, which contains a link to the generated ADI file that we want.
			// Adapted from Original PHP code by Chirp Internet: www.chirp.com.au
			
 			$input = @file_get_contents($eqsl_url) or die("Could not access file: $eqsl_url");
 			
 			// We need to make sure the ADI file has been built before we download it.
			// Look for "Your ADIF log file has been built"
 			
 			// Get all the links on the page and grab the URL for the ADI file.
			$regexp = "<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*)<\/a>";
			if(preg_match_all("/$regexp/siU", $input, $matches)) {
				foreach( $matches[2] as $match )
				{
					// Look for the link that has the .adi file, and download it to $file
					if (substr($match, -4, 4) == ".adi")
					{
						file_put_contents($file, file_get_contents("http://eqsl.cc/qslcard/" . $match));
						ini_set('memory_limit', '-1');
						$this->loadFromFile($file);
						break;
					}
					
					// Produce and error if we don't find the link we need.
    			}
			}
			
			
			
			
		}
		else
		{
			if ( ! $this->upload->do_upload())
			{
			
				$data['error'] = $this->upload->display_errors();

				$this->load->view('layout/header', $data);
				$this->load->view('eqsl/import');
				$this->load->view('layout/footer');
			}
			else
			{
				$data = array('upload_data' => $this->upload->data());
				
				$this->loadFromFile('./uploads/'.$data['upload_data']['file_name']);
			}
		}
	} // end function
	
	public function export() {	
		$this->load->model('logbook_model');
		
		$data['page_title'] = "eQSL QSO Upload";
		
		if ($this->input->post('eqslexport') == "export")
		{
			// Get credentials for eQSL
			$query = $this->user_model->get_by_id($this->session->userdata('user_id'));
    		$q = $query->row();
    		$data['user_eqsl_name'] = $q->user_eqsl_name;
			$data['user_eqsl_password'] = $q->user_eqsl_password;
			
			// Validate that eQSL credentials are not empty
			if ($data['user_eqsl_name'] == '' || $data['user_eqsl_password'] == '')
			{
				$this->session->set_flashdata('warning', 'You have not defined your eQSL.cc credentials!'); redirect('eqsl/import');
			}
			
			// Grab the list of QSOs to send information about
			// perform an HTTP get on each one, and grab the status back
			$qslsnotsent = $this->logbook_model->eqsl_not_yet_sent();
			
			$table = "<table>";
					$table .= "<tr class=\"titles\">";
						$table .= "<td>String</td>";
						$table .= "<td>Result</td>";
					$table .= "<tr>";
			// Build out the ADIF info string according to specs http://eqsl.cc/qslcard/ADIFContentSpecs.cfm
			foreach ($qslsnotsent->result_array() as $qsl)
			{
				$COL_QSO_DATE = date('Ymd',strtotime($qsl['COL_TIME_ON']));
				$COL_TIME_ON = date('Hi',strtotime($qsl['COL_TIME_ON']));
				
				# Set up the single record file
				$adif = "http://www.eqsl.cc/qslcard/importADIF.cfm?";
				$adif .= "ADIFData=CloudlogUpload%20";
				
				/* Handy reference of escaping chars
					"<" = 3C
					">" = 3E
					":" = 3A
					" " = 20
					"_" = 5F
					"-" = 2D
					"." = 2E
				*/
				
				$adif .= "%3C";
				$adif .= "ADIF%5FVER";
				$adif .= "%3A";
				$adif .= "4";
				$adif .= "%3E";
				$adif .= "1%2E00 ";
				$adif .= "%20";
				
				$adif .= "%3C";
				$adif .= "EQSL%5FUSER";
				$adif .= "%3A";
				$adif .= strlen($data['user_eqsl_name']);
				$adif .= "%3E";
				$adif .= $data['user_eqsl_name'];
				$adif .= "%20";
				
				$adif .= "%3C";
				$adif .= "EQSL%5FPSWD";
				$adif .= "%3A";
				$adif .= strlen($data['user_eqsl_password']);
				$adif .= "%3E";
				$adif .= $data['user_eqsl_password'];
				$adif .= "%20";
				
				$adif .= "%3C";
				$adif .= "EOH";
				$adif .= "%3E";
				
				# Lay out the required fields
				$adif .= "%3C";
				$adif .= "QSO%5FDATE";
				$adif .= "%3A";
				$adif .= "8";
				$adif .= "%3E";
				$adif .= $COL_QSO_DATE;
				$adif .= "%20";
				
				$adif .= "%3C";
				$adif .= "TIME%5FON";
				$adif .= "%3A";
				$adif .= "4";
				$adif .= "%3E";
				$adif .= $COL_TIME_ON;
				$adif .= "%20";
				
				$adif .= "%3C";
				$adif .= "CALL";
				$adif .= "%3A";
				$adif .= strlen($qsl['COL_CALL']);
				$adif .= "%3E";
				$adif .= $qsl['COL_CALL'];
				$adif .= "%20";
				
				$adif .= "%3C";
				$adif .= "MODE";
				$adif .= "%3A";
				$adif .= strlen($qsl['COL_MODE']);
				$adif .= "%3E";
				$adif .= $qsl['COL_MODE'];
				$adif .= "%20";
				
				$adif .= "%3C";
				$adif .= "BAND";
				$adif .= "%3A";
				$adif .= strlen($qsl['COL_BAND']);
				$adif .= "%3E";
				$adif .= $qsl['COL_BAND'];
				$adif .= "%20";
				
				# End all the required fields
				
				
				# Tie a bow on it!
				$adif .= "%3C";
				$adif .= "EOR";
				$adif .= "%3E";
				
				$table .= "<tr>";
						$table .= "<td>".$adif."</td>";
						//$result = http_parse_message(http_get($adif))->body;
						$table .= "<td>Result</td>";
				$table .= "<tr>";
			
			}
			// Perform a big HTTP POST with the ADIF information at the back
			// http://www.eqsl.cc/qslcard/ImportADIF.txt
			
			// Dump out a table with the results
			$data['eqsl_table'] = $table;
			
			
			// Things we might get back
			// Result: 0 out of 0 records added -> eQSL didn't understand the format
			// Result: 1 out of 1 records added -> Fantastic
			// Error: No match on eQSL_User/eQSL_Pswd -> eQSL credentials probably wrong
			// Warning: Y=2013 M=08 D=11 F6ARS 15M JT65 Bad record: Duplicate
			//  Result: 0 out of 1 records added -> Dupe, OM!
			
			$this->load->view('layout/header', $data);
			$this->load->view('eqsl/analysis');
			$this->load->view('layout/footer');
		}
		else
		{
			$qslsnotsent = $this->logbook_model->eqsl_not_yet_sent();
		
			if ($qslsnotsent->num_rows() > 0)
			{
				$table = "<table>";
					$table .= "<tr class=\"titles\">";
						$table .= "<td>Date</td>";
						$table .= "<td>Call</td>";
						$table .= "<td>Mode</td>";
						$table .= "<td>Band</td>";
					$table .= "<tr>";
				
				foreach ($qslsnotsent->result_array() as $qsl)
				{
					$table .= "<tr>";
						$table .= "<td>".$qsl['COL_TIME_ON']."</td>";
						$table .= "<td><a class=\"qsobox\" href=\"".site_url('qso/edit')."/".$qsl['COL_PRIMARY_KEY']."\">".strtoupper($qsl['COL_CALL'])."</a></td>";
						$table .= "<td>".$qsl['COL_MODE']."</td>";
						$table .= "<td>".$qsl['COL_BAND']."</td>";
					$table .= "<tr>";
				}
				$table .= "</table>";
		
				$data['eqsl_table'] = $table;
			}
		}
		
		$this->load->view('layout/header', $data);
		$this->load->view('eqsl/export');
		$this->load->view('layout/footer');
		
		/* OLD STUFF from LOTW
			$data = array('upload_data' => $this->upload->data());
			
			// Figure out how we should be marking QSLs confirmed via LoTW
			$query = $query = $this->db->query('SELECT lotw_login_url FROM config');
			$q = $query->row();
			$config['lotw_login_url'] = $q->lotw_login_url;
			
			// Set some fields that we're going to need for ARRL login
			$query = $this->user_model->get_by_id($this->session->userdata('user_id'));
    		$q = $query->row();
    		$fields['login'] = $q->user_lotw_name;
			$fields['password'] = $q->user_lotw_password;
			$fields['acct_sel'] = "";
			
			if ($fields['login'] == '' || $fields['password'] == '')
			{
				$this->session->set_flashdata('warning', 'You have not defined your ARRL LoTW credentials!'); redirect('lotw/status');
			}
				
			// Curl stuff goes here
			
			// First we need to get a cookie

			// options
			$cookie_file_path = "./uploads/cookies.txt";
			$agent            = "Mozilla/4.0 (compatible;)";

			// begin script
			$ch = curl_init(); 

			// extra headers
			$headers[] = "Connection: Keep-Alive";

			// basic curl options for all requests
			curl_setopt($ch, CURLOPT_HTTPHEADER,  $headers);
			curl_setopt($ch, CURLOPT_HEADER,  0);
			
			// TODO: These SSL things should probably be set to true :)
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);         
			curl_setopt($ch, CURLOPT_USERAGENT, $agent); 
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); 
			curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file_path); 
			curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file_path); 

			// Set login URL
			curl_setopt($ch, CURLOPT_URL, $config['lotw_login_url']);
			
			// set postfields using what we extracted from the form
			$POSTFIELDS = http_build_query($fields); 

			// set post options
			curl_setopt($ch, CURLOPT_POST, 1); 
			curl_setopt($ch, CURLOPT_POSTFIELDS, $POSTFIELDS); 

			// perform login
			$result = curl_exec($ch);  
			if (stristr($result, "Username/password incorrect"))
			{
			   $this->session->set_flashdata('warning', 'Your ARRL username and/or password is incorrect.'); redirect('lotw/status');
			}
			
			
			// Now we need to use that cookie and upload the file
			// change URL to upload destination URL
			curl_setopt($ch, CURLOPT_URL, $config['lotw_login_url']);
			
			// Grab the file
			$postfile = array(
        		"upfile"=>"@./uploads/".$data['upload_data']['file_name'],
    		);
    		
    		//Upload it
    		curl_setopt($ch, CURLOPT_POSTFIELDS, $postfile); 
    		$response = curl_exec($ch);
			if (stristr($response, "accepted"))
			{
			   $this->session->set_flashdata('lotw_status', 'accepted');
			   $data['page_title'] = "eQSL Logs Sent";
			} 
			elseif (stristr($response, "rejected"))
			{
					$this->session->set_flashdata('lotw_status', 'rejected');
					$data['page_title'] = "LoTW .TQ8 Sent";
			}
			else
			{
				// If we're here, we didn't find what we're looking for in the ARRL response
				// and LoTW is probably down or broken.
				$this->session->set_flashdata('warning', 'Did not receive proper response from LoTW. Try again later.');
				$data['page_title'] = "LoTW .TQ8 Not Sent";
			}
			
			// Now we need to clean up
			unlink($cookie_file_path);
			unlink('./uploads/'.$data['upload_data']['file_name']);
		
			$this->load->view('layout/header', $data);
			$this->load->view('eqsl/status');
			$this->load->view('layout/footer');
		*/	
	}
	
} // end class