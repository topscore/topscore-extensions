#!/usr/bin/env php
<?php

chdir(__DIR__);

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/config.php';


const API_DOMAIN = 'https://huckcancer.usetopscore.com';
const PRIMARY_DOMAIN = 'http://www.earlyrecognitioniscritical.org';
const REG_URL = API_DOMAIN . "/api/registrations";
const NEW_PRODUCT_URL = API_DOMAIN . "/api/products/new";
const EDIT_ATTRIBUTES_URL_TEMPLATE = API_DOMAIN . "/api/products/edit_donation_product_attributes";
const SEND_MESSAGE_URL = API_DOMAIN . "/api/persons/send-message";

const CREATED_REG_ID_FILE = __DIR__ . '/created';


function getApiCsrf()
{
  $csrfKey = AUTH_SECRET . '|' . floor(date('U') / 3600);

  $td = mcrypt_module_open('rijndael-256', '', 'ecb', '');
  $initializationVector = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_DEV_URANDOM);
  $keySize = mcrypt_enc_get_key_size($td);
  $mcryptKeySeed = CSRF_KEY;
  $key = substr(md5($mcryptKeySeed), 0, $keySize);
  mcrypt_generic_init($td, $key, $initializationVector);

  $encrypted = mcrypt_generic($td, $csrfKey);

  mcrypt_generic_deinit($td);
  mcrypt_module_close($td);

  return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
}





$guzzle = new GuzzleHttp\Client();
$apiCsrf = getApiCsrf();

$createdIds = [];

if (file_exists(CREATED_REG_ID_FILE))
{
  $createdIds = (array) array_filter(explode("\n", file_get_contents(CREATED_REG_ID_FILE)));
}

$count = 0;
$page = 1;

while (true)
{
  echo "Getting registration data\n";
  $registrationData = $guzzle->get(REG_URL, [
    'verify' => false,
    'query' => [
      'api' => 1,
      '_auth' => AUTH_KEY,
      'event_id' => 106689,
      'statuses' => 'accepted',
      'per_page' => 100,
      'page' => $page,
      'fields' => ['Person', 'Event']
    ]
  ])->json();

  if ($registrationData === null || !is_array($registrationData) || $registrationData['success'] !== true)
  {
    echo "Could not get reg data\n";
    var_export($registrationData);
    exit(1);
  }

  try
  {
    foreach($registrationData['result'] as $reg)
    {
      if (!$reg['person_id'])
      {
        echo "No person id for " . $reg['id'] . "\n";
        continue;
      }

      if (in_array($reg['id'], $createdIds))
      {
        echo "Skipping " . $reg['id'] . "\n";
        continue;
      }

      var_export($reg);

      try {
      $newProductData = $guzzle->post(NEW_PRODUCT_URL, [
        'verify' => false,
        'body' => [
          '_auth' => AUTH_KEY,
          'api_csrf' => $apiCsrf,
  //        'site_id' => $reg['Event']['site_id'],
          'organization_id' => $reg['Event']['organization_id'],
          'product_category_id' => 8, // donation
          'name' => 'Donate to ' . $reg['Person']['full_name'] . ' for ' . $reg['Event']['name'],
          'is_active' => 1,
          'enable_variations' => 1
        ]
      ])->json();
      } catch (\GuzzleHttp\Exception\RequestException $re) {
        if ($re && $re->getResponse())
        {
          $json = $re->getResponse()->json();
          if ($json && isset($json['errors']) && isset($json['errors']['name']) && $json['errors']['name'] == 'A product with this name already exists.')
          {
            $createdIds[] = $reg['id'];
            file_put_contents(CREATED_REG_ID_FILE, $reg['id']."\n", FILE_APPEND); // do it now because product is created now. if the rest fails, its ok we can just do it by hand.
            continue;
          }
        }
        throw $re;
      }

      if ($newProductData === null || !is_array($newProductData) || $newProductData['success'] !== true)
      {
        echo "Creating new product failed.\n";
        var_export($newProductData);
        exit(1);
      }


      $createdIds[] = $reg['id'];
      file_put_contents(CREATED_REG_ID_FILE, $reg['id']."\n", FILE_APPEND); // do it now because product is created now. if the rest fails, its ok we can just do it by hand.

      var_export($newProductData);

      foreach(array_merge([$newProductData['result']], $newProductData['result']['Family']) as $product)
      {
        $editAttributesData = $guzzle->post(EDIT_ATTRIBUTES_URL_TEMPLATE, [
          'verify' => false,
          'body' => [
            '_auth' => AUTH_KEY,
            'api_csrf' => $apiCsrf,
            'product_id' => $product['id'],
            'person_id' => $reg['person_id'],
            'is_price_variable' => $product['is_full_product'] && stripos($product['name'], 'custom') !== false,
            'is_donated_amount_public' => true
          ]
        ])->json();

        if ($editAttributesData === null || !is_array($editAttributesData) || $editAttributesData['success'] !== true)
        {
          echo "Editing dontaion attributes failed.\n";
          var_export($editAttributesData);
          exit(1);
        }

        var_export($editAttributesData);
      }

      $productUrl = PRIMARY_DOMAIN . '/s/' . $newProductData['result']['id'] . '/' . urlencode($newProductData['result']['name']);

      echo "Sending email \n";
      $messageData = $guzzle->post(SEND_MESSAGE_URL, [
        'verify' => false,
        'body' => [
          '_auth' => AUTH_KEY,
          'api_csrf' => $apiCsrf,
          'recipient_id' => $reg['person_id'],
          'message' => <<<EOF
Thanks for registering for Huck Cancer!<br>
<br>
You can access your individual page here: <a href="$productUrl">$productUrl</a>.<br>
<br>
You can personalize your fundraising page so donors can easily donate to you! Here are some ideas to personalize your page:<br>
<br>
- Why is early cancer detection important to you? Maybe share a short story about a friend, family member or yourself that fought cancer through early detection.<br>
- What is your fundraising goal? That helps people know how far you are from your goal and may help push you over the edge.<br>
- Thank them for visiting the page!<br>
- Some facts about E.R.I.C. to help - Early Recognition Is Critical are a non-profit so all donations are tax-deductible. We use Ultimate Frisbee
  as the vehicle to teach kids about early cancer detection and body awareness. We sponsor and run Clinics across the country in middle school P.E.
  classes and are fundraising for supplies for the Clinics, as well as other programs to promote early cancer detection.<br>
<br>
Remember, this is a fundraiser tournament so you're required to raise $50! If you ask five people to donate $10, you've earned it.<br>
<br>
If you don't meet your requirement, we will ask for the balance of $50 on the day of the tournament at check-in.
Help us spread the message of early cancer prevention to kids across the country!<br>
EOF
        ]
      ])->json();

      if ($messageData === null || !is_array($messageData) || $messageData['success'] !== true)
      {
        echo "Sending message to " . $reg['person_id'] . "failed\n";
        var_export($messageData);
        exit(1);
      }
    }
  }
  catch (\GuzzleHttp\Exception\RequestException $re)
  {
    echo '1 ' . get_class($re) . "\n";
    echo $re->getRequest() . "\n";
    if ($re->hasResponse())
    {
      echo $re->getResponse() . "\n";
    }
    exit(1);
  }
  catch (\GuzzleHttp\Exception\TransferException $e)
  {
    echo '2 ' . get_class($e) . "\n";
    echo $e->getResponse() . "\n";
    exit(1);
  }

  $count += count($registrationData['result']);
  if ($count >= $registrationData['count'])
  {
    break;
  }
  $page++;
}
exit(0);
