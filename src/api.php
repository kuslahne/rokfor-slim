<?php

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Hmac\Sha256;

$app->group('/api', function () {
  /*
   * Pretty Print JSON
   */

//    define('JSON_CONSTANTS', JSON_PRETTY_PRINT);
    define('JSON_CONSTANTS', 0);

  /*  Contributions Access
   *
   *  Issue and chapter is either an integer or a combination of values seperated
   *  with a Hypen: i.e. x or x-x
   *
   *  Additional query parameters:
   *  - query=string
   *  - sort=[id|date|name|sort or chapter or issue or fieldname]:[asc|desc]
   *  - limit=int
   *  - offset=int
   *  - filter=[int|...]:[lt|gt|eq|like] (default operator: like)
   *  - data=[Fieldname|...]             (default: empty)
   *  - populate=true|false              (default: false)
   *  - verbose=true|false               (default: false)
   *  - template=id                      (default: false)
   *  - status=draft|published|both      (default: published)
   *  - references=true|false            (default: true)
   */
  $this->options('/contributions/{issue:[0-9-]*}/{chapter:[0-9-]*}',
    function ($request, $response, $args) {}
  );

  $this->get('/contributions/{issue:[0-9-]*}/{chapter:[0-9-]*}',
    function ($request, $response, $args) {
    $j = [];
    $_fids = [];
    $_cache_expiration = false;
    if (stristr($args['issue'],'-')) {
      $args['issue'] = explode('-', $args['issue']);
    }
    if (stristr($args['chapter'],'-')) {
      $args['chapter'] = explode('-', $args['chapter']);
    }

    $compact = $request->getQueryParams()['verbose'] == "true" ? false : true;
    $follow_references = $request->getQueryParams()['references'] == "false" ? false : true;    
    $_limit    = isset($request->getQueryParams()['limit']) ? intval($request->getQueryParams()['limit']) : null;
    $_offset   = isset($request->getQueryParams()['offset']) ? intval($request->getQueryParams()['offset']) : null;
    $_query    = isset($request->getQueryParams()['query']) ? $request->getQueryParams()['query'] : false;
    $_template = isset($request->getQueryParams()['template']) ? (int)$request->getQueryParams()['template'] : false;
    $_sort     = isset($request->getQueryParams()['sort']) ? $request->getQueryParams()['sort'] : 'asc';

    // Translate $_status to Rokfor Standards
    $_status   = 'Close';
    switch (strtolower($request->getQueryParams()['status'])) {
      case 'draft':
        $_status = 'Draft';
        break;
      case 'both':
        $_status = ['Draft', 'Close'];
        break;
      default:
        $_status = 'Close';
        break;
    }

    // Parse Query Strings...
    if ($_query == "date:now") {
      $_query = time();
    }

    list($filterfields, $filterclause) = explode(':',$request->getQueryParams()['filter']);
    $qt = microtime(true);
    $c = $_query !== false
          ? $this->db->searchContributions($_query, $args['issue'], $args['chapter'], $_status, $_limit, $_offset, $filterfields, $filterclause, $_sort, false, $_template)
          : $this->db->getContributions($args['issue'], $args['chapter'], $_sort, $_status, $_limit,  $_offset, false, $_template);

    if (is_object($c)) {
      // Counting Max Objects without pages and limits
      $_count = $_query !== false
          ? $this->db->searchContributions($_query, $args['issue'], $args['chapter'], $_status, false, false, $filterfields, $filterclause, false, true, $_template)
          : $this->db->getContributions($args['issue'], $args['chapter'], false, $_status, false, false, true, $_template);

      foreach ($c as $_c) {
        // Check for publish date.
        $_config = json_decode($_c->getConfigSys());
        if (is_object($_config) && $_config->lockdate > time()) {
          if ($_config->lockdate < $_cache_expiration || $_cache_expiration === false) {
            $_cache_expiration = $_config->lockdate;
          }
          continue;
        }
        // No Recursion on multiple contributions
        $recursion = false;
        // Creating Cache Signature
        $signatur_fields = explode("|", strtolower($request->getQueryParams()['data']));
        sort($signatur_fields);
        $signature = md5($compact."-".$follow_references."-".$recursion."-".$request->getQueryParams()['populate'].join(".",$signatur_fields));
        // Asking Cache first
        if ($h = $_c->checkCache($signature)) {
          $_contribution["Contribution"] = $h->Contribution;
          $_contribution["Data"]         = $h->Data;
        }
        // Create new Entry and store in Cache
        else {
          $_contribution["Contribution"]  = $this->helpers->prepareApiContribution($_c, $compact, $request, [], $recursion, $follow_references);
          $_contribution["Data"]          = $this->helpers->prepareApiContributionData($_c, $compact, $request, $recursion);
          $this->db->NewContributionCache($_c, ["Contribution" => $_contribution["Contribution"], "Data" => $_contribution["Data"]], $signature);
        }
        $j[] = $_contribution;
      }
      if ($this->get('redis')['client']) {
        $this->get('redis')['client']->set('expiration', $_cache_expiration);
      }
      $response->getBody()->write(
        json_encode(
                    array("Documents" => $j,
                          "NumFound"  => $_count,
                          "Limit"     => count($c),
                          "Offset"    =>  $_offset,
                          "QueryTime" => (microtime(true) - $qt),
                          "Hash"      => md5(json_encode($j))
                    ),
                    JSON_CONSTANTS
                   )
      );
    }
    else {
      $errcode = 500;
      $newResponse = $response->withStatus($errcode)->getBody()->write(json_encode(['Error'=>'Access denied to the selected issues and chapters.'], JSON_CONSTANTS));
      return $newResponse;
    }
  }
  );

  /* Single Contribution
   *
   *  - verbose=true|false
   */
  $this->options('/contribution/{id:[0-9]*}',
    function ($request, $response, $args) {}
  );

  $this->get('/contribution/{id:[0-9]*}',
    function ($request, $response, $args) {
      $j = [];
      $qt = microtime(true);
      $compact = $request->getQueryParams()['verbose'] ? false : true;
      $c = $this->db->getContribution($args['id']);
      if ($c && ($c->getStatus()=="Close" || $c->getStatus()=="Draft")) {
        $signature = md5($compact);
        if ($h = $c->checkCache($signature)) {
          $jc = $h->Contribution;
          $j  = $h->Data;
        }
        else {
          $jc = $this->helpers->prepareApiContribution($c, $compact);
          $j  = $this->helpers->prepareApiContributionData($c, $compact);
          $this->db->NewContributionCache($c, ["Contribution" => $jc, "Data" => $j], $signature);
        }
        $response->withHeader('Content-type', 'application/json')->getBody()->write(json_encode([
          "Contribution"              => $jc,
          "Data"                      => $j,
          "QueryTime"                 => (microtime(true) - $qt),
          "Hash"                      => md5(json_encode([$j, $jc])),
        ], JSON_CONSTANTS));
      }
      else if ($c === false) {
        $errcode = 500;
        $newResponse = $response->withHeader('Content-type', 'application/json')->withStatus($errcode);
        $newResponse->getBody()->write(json_encode(['Error'=>'No access to Element'], JSON_CONSTANTS));
        return $newResponse;
      }
      else {
        $errcode = 500;
        $newResponse = $response->withHeader('Content-type', 'application/json')->withStatus($errcode);
        $newResponse->getBody()->write(json_encode(['Error'=>'Element not found'], JSON_CONSTANTS));
        return $newResponse;
      }
    }
  );

  /* Books
   *
   *  - populate=true|false              (default: false)
   *  - verbose=true|false               (default: false)
   *  - data=[Fieldname|...]             (default: empty)
   */
  $this->options('/books[/{id:[0-9]*}]',
    function ($request, $response, $args) {}
  );

  $this->get('/books[/{id:[0-9]*}]',
    function ($request, $response, $args) {
      $qt = microtime(true);
      $b = $this->db->getStructure("", $args['id']);
      $compact = $request->getQueryParams()['verbose'] == "true" ? false : true;

      if ($b) {
        $j = [];
        foreach ($b as $_book) {
          $_chapters = [];
          $_issues = [];
          foreach ($_book["chapters"] as $_chapter) {
             $_chapters[] = $this->helpers->prepareApiStructureInfo($_chapter["chapter"], true, $compact, $request);
          }
          foreach ($_book["issues"] as $_issue) {
            $_issues[] = $this->helpers->prepareApiStructureInfo($_issue["issue"], true, $compact, $request);
          }
          $j[] = array_merge($this->helpers->prepareApiStructureInfo($_book["book"], true, $compact, $request), ["Chapters" => $_chapters, "Issues" => $_issues]);
        }
        $response->getBody()->write(json_encode([
          "Books"                     => $j,
          "QueryTime"                 => (microtime(true) - $qt),
          "Hash"                      => md5(json_encode($j))
        ], JSON_CONSTANTS));
      }
      else if ($b === false) {
        $errcode = 404;
        $newResponse = $response->withStatus($errcode);
        $newResponse->getBody()->write(json_encode(['code'=>$errcode, 'message'=>'No access to Book'], JSON_CONSTANTS));
        return $newResponse;
      }
      else {
        $errcode = 404;
        $newResponse = $response->withStatus($errcode);
        $newResponse->getBody()->write(json_encode(['code'=>$errcode, 'message'=>'Book not found'], JSON_CONSTANTS));
        return $newResponse;
      }
    }
  );

  /* Issues & Chapters
   *
   *  - populate=true|false              (default: false)
   *  - verbose=true|false               (default: false)
   *  - data=[Fieldname|...]             (default: empty)
   */
  $this->options('/{action:issues|chapters}[/{id:[0-9]*}]',
    function ($request, $response, $args) {}
  );

  $this->get('/{action:issues|chapters}[/{id:[0-9]*}]',
    function ($request, $response, $args) {

      // Actions
      $funcname = 'getStructureBy'.ucfirst($args['action']);

      $qt = microtime(true);
      $i = $this->db->$funcname($args['id']);
      $compact = $request->getQueryParams()['verbose'] == "true" ? false : true;

      if ($i) {
        $j = [];
        foreach ($i as $_issue) {
          $j[] = $this->helpers->prepareApiStructureInfo($_issue, true, $compact, $request);
        }
        $response->getBody()->write(json_encode([
          ucfirst($args['action'])    => $j,
          "QueryTime"                 => (microtime(true) - $qt),
          "Hash"                      => md5(json_encode($j))
        ], JSON_CONSTANTS));
      }
      else if ($i === false) {
        $errcode = 404;
        $newResponse = $response->withStatus($errcode);
        $newResponse->getBody()->write(json_encode(['code'=>$errcode, 'message'=>'No access to '.ucfirst($args['action'])], JSON_CONSTANTS));
        return $newResponse;
      }
      else {
        $errcode = 404;
        $newResponse = $response->withStatus($errcode);
        $newResponse->getBody()->write(json_encode(['code'=>$errcode, 'message'=>ucfirst($args['action']).' not found'], JSON_CONSTANTS));
        return $newResponse;
      }
    }
  );


  /* Binary Proxy
   *
   *
   */
  $this->options('/proxy/{id:[0-9]*}/{file}',
    function ($request, $response, $args) {}
  );

  $this->get('/proxy/{id:[0-9]*}/{file}',
    function ($request, $response, $args) {
      $c = $this->db->getContribution($args['id']);
      if ($c && ($c->getStatus()=="Close" || $c->getStatus()=="Draft")) {
        $url = base64_decode($args['file'], true);
        if ($url !== false) {
          $this->get('logger')->info("DECODING: ".$url);
          return $this->db->proxy($url, $response);
        }
        else {
          $errcode = 500;
          $newResponse = $response->withStatus($errcode);
          $newResponse->getBody()->write(json_encode(['Error'=>'Base64 encoding failed'], JSON_CONSTANTS));
          return $newResponse;
        }
      }
      else if ($c === false) {
        $errcode = 500;
        $newResponse = $response->withStatus($errcode);
        $newResponse->getBody()->write(json_encode(['Error'=>'No access to Contribution'], JSON_CONSTANTS));
        return $newResponse;
      }
      else {
        $errcode = 500;
        $newResponse = $response->withStatus($errcode);
        $newResponse->getBody()->write(json_encode(['Error'=>'Contribution not found'], JSON_CONSTANTS));
        return $newResponse;
      }

      $errcode = 500;
      $newResponse = $response->withStatus($errcode);
      $newResponse->getBody()->write(json_encode(['Error'=>'Unknown Error'], JSON_CONSTANTS));
      return $newResponse;
    }
  );

  /* Login
   * Required for R/W Access. Login with username and R/W Key
   * Returns a JWT Token for further usage
   *
   */

  $this->options('/login',
    function ($request, $response, $args) {}
  );

  $this->post('/login',
    function ($request, $response, $args) {

      $u = $this->db->getUsers()
            ->filterByRwapikey($request->getParsedBody()['apikey'])
            ->filterByUsername($request->getParsedBody()['username'])
            ->limit(1)
            ->findOne();
      if ($u) {
        $this->db->setUser($u->getId());
        $this->db->addLog('post_api', 'POST' , $request->getAttribute('ip_address'));
        $signer = new Sha256();
        $token = (new Builder())->setIssuer($_SERVER['HTTP_HOST'])    // Configures the issuer (iss claim)
                                ->setAudience($_SERVER['HTTP_HOST'])  // Configures the audience (aud claim)
                                ->setId(uniqid('rf', true), true)     // Configures the id (jti claim), replicating as a header item
                                ->setIssuedAt(time())                 // Configures the time that the token was issue (iat claim)
                                ->setNotBefore(time())                // Configures the time that the token can be used (nbf claim)
                                ->setExpiration(time() + 3600)        // Configures the expiration time of the token (nbf claim)
                                ->set('uid', $u->getId())             // Configures a new claim, called "uid"
                                ->sign($signer,  $u->getRwapikey()) // creates a signature using "testing" as key
                                ->getToken();                         // Retrieves the generated token
        $r = $response->withHeader('Content-type', 'application/json');
        $r->getBody()->write(json_encode((string)$token));
        return $r;
      }
      else {
        $r = $response->withHeader('Content-type', 'application/json')->withStatus(500);
        $r->getBody()->write(json_encode(["Error" => "Wrong key supplied"]));
        return $r;
      }
    }
  );

  /* Put Contribution
   * Adding a contribution
   *
   * The body of the put request must be a json string defining at least the following parameters:
   *
   * {"Template": Int, "Name": String, "Chapter": Int, "Issue": Int}
   *
   * Optional, the Status of the newly created contribution can be passed as well:
   *
   * {"Template": Int, "Name": String, "Chapter": Int, "Issue": Int, "Status": "Draft|Published|Open|Deleted"}
   *
   */

  $this->options('/contribution',
    function ($request, $response, $args) {}
  );

  $this->put('/contribution',
    function ($request, $response, $args) {

      // Creating a Response

      $r = $response->withHeader('Content-type', 'application/json');

      // Payload: Encoding JSON Body

      if ($data = json_decode($request->getBody())) {
        $_error = false;  // Error Message
        $c = false;       // Contribution Return value
        $i = false;       // Issue Object
        $f = false;       // Chapter Object
        $_status = "Open";

        // Check Payload Structure

        if (!is_int($data->Template))
          $_error = 'Template Id missing or not an integer value.';
        if (!is_int($data->Chapter))
          $_error = 'Chapter Id missing or not an integer value.';
        if (!is_int($data->Issue))
          $_error = 'Issue Id missing or not an integer value.';
        if (!is_string($data->Name))
          $_error = 'Contribution Name missing or not a string.';
        if (is_string($data->Status)) {
          if ($data->Status == "Draft" || $data->Status == "Deleted") $_status = $data->Status;
          if ($data->Status == "Published") $_status = "Close";
        }

        // Continue if ok

        if ($_error === false) {

          // Check Issue Access for the current User (determined in the JWT Token)

          $i = $this->db->getStructureByIssues($data->Issue);
          if (is_array($i)) {
            $i = $i[0];
          }

          // Check Chapter Access for the current User (determined in the JWT Token)

          $f = $this->db->getStructureByChapters($data->Chapter);
          if (is_array($f)) {
            $f = $f[0];
          }

          // Check for valid Issue

          if (!$i) {
            $_error = "Issue does not exist or user has no access.";
          }

          // Check for valid Chapter

          else if (!$f) {
            $_error = "Chapter does not exist or user has no access.";
          }

          // Check for valid Book Association

          else if ($i->getForbook() !== $f->getForbook()) {
            $_error = "Issue and chapter are not in the same book.";
          }

          // Check for Template permission within the given Chapter

          else {
            $template_ok = false;
            foreach ($this->db->getTemplates($f) as $allowedTemplate) {
              if ($allowedTemplate["id"] === $data->Template) {
                $template_ok = true;
              }
            }
            if ($template_ok === false) {
              $_error = "Template id not valid or not allowed within this chapter or issue.";
            }
          }

          // Continue if no error is raised

          if ($_error === false) {
            $c = $this->db->NewContribution($i, $f, $data->Template, $data->Name, $_status);

            // Store
            if ($c !== false && gettype($c) == "object") {
              $r->getBody()->write(json_encode([
                "Id" => $c->getId(),
                "ModDate" => $c->getModdate()
              ]));
            }
            else {
              $_error = "Error creating contribution.";
            }
          }
        }
      }
      else {
        $_error = "Body is not a valid json string.";
      }
      if ($_error) {
        $r = $response->withHeader('Content-type', 'application/json')->withStatus(500);
        $r->getBody()->write(json_encode(["Error" => $_error]));
      }
      return $r;
    }
  );

  /* Post Contribution
   * Storing Data in a contribution with id :id and changing the contribution itself. All parameters are optional
   *
   * {"Template": Int, "Name": String, "Chapter": Int, "Issue": Int, "Status": "Draft|Published|Open|Deleted", "Data":{field:value, field:value...}}
   *
   *
   * Binary File Handling:
   * ---------------------
   *
   * If the content type is multipart/form-data, the JSON Payload needs to be passed
   * within a POST parameter named "body"
   * Normally, files are appended to the already existing list of files in a field
   * Uploads:
   * "Field_Name": {"multipart_1":"Caption"|["Caption 1", "Caption 2" ...],"multipart_x":"Caption"|["Caption 1", "Caption 2" ...] ...}
   * Delete all binaries:
   * "Field_Name": {}
   * Delete one binary:
   * "Field_Name": {"filename.jpg":{}}
   * Rename one binary:
   * "Field_Name": {"filename.jpg":"New Caption"}
   */

  $this->post('/contribution/{id:[0-9]*}',
    function ($request, $response, $args) {
      $r = $response->withHeader('Content-type', 'application/json');
      $_error = false;

      // Check for Valid Payload, either json body
      // or a json string in the json post variable

      $_json = ($request->getParsedBody()['body'] ? $request->getParsedBody()['body'] : $request->getBody());

      if ($data = json_decode($_json)) {

        // Check if the contribution exists and the user has access to it

        if ($c = $this->db->getContribution($args['id'])) {

          // Contribution Level Modification

          // 1 Change State - this can be done always.

          if (is_string($data->Status)) {
            $_error = "Status must be Open, Draft, Deleted or Published.";
            if ($data->Status == "Open"  ||
                $data->Status == "Draft" ||
                $data->Status == "Deleted") {
              $_status = $data->Status;
              $_error = false;
            }
            if ($data->Status == "Published") {
              $_status = "Close";
              $_error = false;
            }
            if ($_error === false) {
              $c->setStatus($_status);
            }
          }

          // 2 Change Template
          $_template = is_int($data->Template) ? $data->Template : $c->getFortemplate();

          // 3 Change Issue
          $_issue =  is_int($data->Issue) ? $data->Issue : $c->getForissue();

          // 4 Change Chapter
          $_chapter = is_int($data->Chapter) ? $data->Chapter : $c->getForchapter();

          // 5 Change Sort Order
          $_sort = is_int($data->Sort) ? $data->Sort : $c->getSort();


          // Exectute the changements: Only if one of the relevant paramters are
          // passed.
          if (is_int($data->Chapter) || is_int($data->Issue) || is_int($data->Template) || is_int($data->Sort)) {

            // Check Issue Access for the current User (determined in the JWT Token)

            $i = $this->db->getStructureByIssues($_issue);
            if (is_array($i)) {
              $i = $i[0];
            }

            // Check Chapter Access for the current User (determined in the JWT Token)

            $f = $this->db->getStructureByChapters($_chapter);
            if (is_array($f)) {
              $f = $f[0];
            }

            // Check for valid Issue

            if (!$i) {
              $_error = "Issue does not exist or user has no access.";
            }

            // Check for valid Chapter

            else if (!$f) {
              $_error = "Chapter does not exist or user has no access.";
            }

            // Check for valid Book Association

            else if ($i->getForbook() !== $f->getForbook()) {
              $_error = "Issue and chapter are not in the same book.";
            }

            // Check for Template permission within the given Chapter

            else {
              $template_ok = false;
              foreach ($this->db->getTemplates($f) as $allowedTemplate) {
                if ($allowedTemplate["id"] === $_template) {
                  $template_ok = true;
                }
              }
              if ($template_ok === false) {
                $_error = "Template id not valid or not allowed within this chapter or issue.";
              }
            }

            // Continue if no error is raised

            if ($_error === false) {

              if ($_template != $c->getFortemplate()) {
                $this->db->ChangeTemplateContribution($c->getId(), $_template);
              }

              if ($_issue != $c->getForissue()) {
                $c->setForissue($_issue);
              }

              if ($_chapter != $c->getForchapter()) {
                $c->setForchapter($_chapter);
              }

              if ($_sort != $c->getSort()) {
                $c->setSort($_sort);
              }

            }
          }

          // 5 Rename if Name Parameter is set
          if (is_string($data->Name)) {
            if ($data->Name !== "")
              $c->setName($data->Name);
            else
              $_error = "Name must not be an empty string. Omit Name completely if it should be ignored.";
          }

          if ($_error === false) {
            $c->updateCache();
            $c->setModdate(time());
            // Store Contribution
            $c->save();
            // Data Level Modification - Loop trough fields and store data
            if (is_object($data->Data)) {
              // Convert Datas into associative Array
              $d = [];
              // Return Values from Store Actions
              $_data_store = [];
              foreach ($c->getDatas() as $_data) {
                $d[$_data->getTemplates()->getFieldname()] = $_data;
              }
              // Pass One: Control Fields
              foreach ($data->Data as $fieldname => $fieldvalue) {
                if (!$d[$fieldname]) {
                  $_error = "Field $fieldname does not exist in this template.";
                }
              }
              if ($_error === false) {
                foreach ($data->Data as $fieldname => $fieldvalue) {
                  $field = $d[$fieldname];
                  $type     = $field->getTemplates()->getFieldtype();
                  $settings = json_decode($field->getTemplates()->getConfigSys(), true);
                  switch ($type) {

                    // Binary Uploads
                    case 'Bild':


                      // Clear Fields if empty data is passed

                      if (empty((array) $fieldvalue)) {
                         $_data_store[] = $this->db->FileModify($field->getId(),  []);
                      }

                      // Cycle thru uploads
                      foreach ((array)$fieldvalue as $file_part_name => $captions) {

                        // Add the file if a uploaded filed with the same key existts

                        if ($file = $request->getUploadedFiles()[$file_part_name]) {
                          $process = [];
                          $_data_store[] = $this->db->FileStore(
                            $field->getId(),
                            $file,
                            $process['original'],
                            $process['relative'],
                            $process['thumb'],
                            $process['caption'],
                            $process['newindex'],
                            $captions);
                        }

                        // Otherwise cycle through the existing data and change captions

                        else {
                          $_old_data = json_decode($field->getContent(), true);
                          $_to_delete = [];
                          foreach ($_old_data as $_old_image_key =>&$_old_image) {
                            if ($_old_image[1] == $file_part_name) {
                              // Empty Captions - delete
                              if (empty((array) $captions)) {
                                $_to_delete[] = $_old_image_key;
                                $this->db->DeleteFiles((array)$_old_image[1], (array)$_old_image[2]['thumbnail'], (array)$_old_image[2]['scaled'],  !$c->getTemplatenames()->getPublic());
                              }

                              // Rename
                              else {
                                if (is_array($_old_image[0])) {
                                  foreach ($_old_image[0] as $_old_caption_key => &$_old_caption)  {
                                    $_old_caption = $captions[$_old_caption_key] ? $captions[$_old_caption_key] : "";
                                  }
                                }
                                else {
                                  $_old_image[0] = $captions;
                                }
                              }
                            }
                          }
                          // Delete all with empty captions
//                        print_r($_old_data);
//                          print_r($_to_delete);

                          foreach ($_to_delete as $_delkey) {
                            unset($_old_data[$_delkey]);
                          }
                          // Store
                          $field->setContent(json_encode($_old_data))
                            ->setIsjson(true)
                            ->save();
                        }
                      }




                      /*
                      $data = json_decode($request->getParsedBody()['data'], true);
                      if ((is_object($file) && $file->getError() == 0) && $data['action'] == 'add') {
                        $json['success'] = $this->db->FileStore($args['id'], $file, $json['original'], $json['relative'], $json['thumb'], $json['caption'], $json['newindex']);
                        $json['growing'] = $settings['growing'];
                      }
                      else if ($data['action'] == 'modify') {
                        $json['success'] = $this->db->FileModify($args['id'],  $data['data']);
                      }*/
                    break;

                    default:
                      # code...
                      $_data_store[] = $this->db->setField($field->getId(), $fieldvalue);
                      break;
                  }
                }
              }
            }
            if ($_error === false) {
              $r->getBody()->write(json_encode([
                "Id" => $c->getId(),
                "Data" => $_data_store,
                "ModDate" => $c->getModdate()
              ]));
            }
          }

        }
        else {
          $_error = 'Contribution Id not known or User has no access to modify it.';
        }
      }
      else {
        $_error = "Body is not a valid json string.";
      }

      // Return error message

      if ($_error) {
        $r = $response->withHeader('Content-type', 'application/json')->withStatus(500);
        $r->getBody()->write(json_encode(["Error" => $_error]));
      }
      return $r;
    }
  );

  /* Delete Contribution
   *
   */

  $this->delete('/contribution/{id:[0-9]*}',
    function ($request, $response, $args) {
      $r = $response->withHeader('Content-type', 'application/json');
      $_error = false;
      $c = $this->db->getContribution($args['id']);

      if ($c === null)
        $_error = "Contribution id does not exist.";
      else if ($c === false)
        $_error = "No access for this contribution.";
      else {
        $this->db->DeleteContributions([$args['id']]);
        $r->getBody()->write(json_encode(["Id" => $args['id']]));
        return $r;
      }

      // Return error message
      $r->withStatus(500)->getBody()->write(json_encode(["Error" => $_error]));
      return $r;
    }
  );


})->add($redis)->add($apiauth);

/* Asset Rewriting - only running on nginx. all other servers are just redirecting to */

$app->options('/asset/{id:[0-9]*}/{field:[0-9]*}/{file:.+}',
  function ($request, $response, $args) {}
);

$app->get('/asset/{id:[0-9]*}/{field:[0-9]*}/{file:.+}', function ($request, $response, $args) {

  // Check if user is logged in
  $auth      = new \Zend\Authentication\AuthenticationService();
  $logged_in = $auth->getIdentity()['username'];
  $apikey    = false;
  $access    = false;
  $s3        = $this->get('settings')['paths']['s3'];

  // Check for existing contribution. If logged in ignore state, otherwise just published or draft
  $c = $this->db->getContribution($args['id'], $logged_in ? false : true, true);

  if (!($c && $c->getId() == $args['id'])) {
    throw new \Slim\Exception\NotFoundException($request, $response);
  }

  // Public
  $public = $c->getTemplatenames()->getPublic() === "1";

  // Check for field
  $f = $this->db->getField($args['field']);

  // Check for NGINX
  $_isnginx = (strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false);
  // Check for Authentification if Public = 0

  if (!$public) {
    if ($request->getQueryParams()['access_token']) {
      $apikey = $request->getQueryParams()['access_token'];
    }
    else {
      $bearer = $request->getHeader('Authorization');
      if ($bearer[0]) {
        preg_match('/^Bearer (.*)/', $bearer[0], $bearer);
        $apikey = $bearer[1];
      }
    }
    if ($apikey !== false) {
      $u = $this->db->getUsers()
            ->filterByRoapikey($apikey)
            ->limit(1)
            ->findOne();
      if ($u) {
        $access = true;
      }
    }
  }

  // Do the presigining if contribution
  if (($public || $access || $logged_in) && stristr($f->getContent(), $args['file'])) {
    ob_end_flush();
    if ($s3 === true) {
      if ($_isnginx === true) {
        header('X-Accel-Redirect: /cdn/' . str_replace('https://', '', $this->db->presign_file($args['file'])));
      }
      else {
        header('Location: ' . $this->db->presign_file($args['file']));
      }
    }
    else {
      if ($_isnginx === true) {
        if ($_file = $this->db->presign_file($args['file'], $public, false)) {
          header('X-Accel-Redirect: /cdn-local'.($public?'-public':'-private') . $_file);
        }
        else {
          throw new \Slim\Exception\NotFoundException($request, $response);
        }
      }
      else {
        if ($_file = $this->db->presign_file($args['file'], $public)) {
          header('Content-Type: '.mime_content_type($_file));
          header('Expires: 0');
          header('Cache-Control: must-revalidate');
          header('Pragma: public');
          header('Content-Length: ' . filesize($_file));
          readfile($_file);
        }
        else {
          throw new \Slim\Exception\NotFoundException($request, $response);
        }
      }
    }
    exit(0);
  }
  else {
    throw new \Slim\Exception\NotFoundException($request, $response);
  }
  return $response;
});
