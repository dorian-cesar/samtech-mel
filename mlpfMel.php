<?php

set_time_limit(1200);

$user = "Mel";
$pasw = "123";

include "login/conexion.php";

$consulta = "SELECT hash FROM masgps.hash where user='$user' and pasw='$pasw'";
$resultado = mysqli_query($mysqli, $consulta);
$data = mysqli_fetch_array($resultado);
$hash = $data['hash'];
$cap = $hash;

// Inicializa la primera llamada para obtener el listado de trackers
$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/tracker/list',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS => '{"hash":"' . $cap . '"}',
  CURLOPT_HTTPHEADER => array(
    'Accept: application/json, text/plain, */*',
    'Content-Type: application/json',
  ),
));

$response = curl_exec($curl);
curl_close($curl);

$json = json_decode($response);
$array = $json->list;

// Aquí comienza la lógica de multicurl
$multiCurl = curl_multi_init();
$curls = [];
$results = [];
$total = [];
$i = 0;

// Configurar las solicitudes a la API `get_state` para cada tracker
foreach ($array as $item) {
  $id = $item->id;
  $plate = substr($item->label, 0, 7);

  $curls[$i] = curl_init();
  curl_setopt_array($curls[$i], array(
    CURLOPT_URL => 'http://www.trackermasgps.com/api-v2/tracker/get_state',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => '{"hash": "' . $cap . '", "tracker_id": ' . $id . '}',
    CURLOPT_HTTPHEADER => array(
      'Content-Type: application/json'
    ),
  ));

  curl_multi_add_handle($multiCurl, $curls[$i]);
  $i++;
}

// Ejecutar las solicitudes en paralelo
$running = null;
do {
  curl_multi_exec($multiCurl, $running);
  curl_multi_select($multiCurl);
} while ($running > 0);

// Recoger las respuestas
foreach ($curls as $i => $curl) {
  $response = curl_multi_getcontent($curl);
  $json2 = json_decode($response);

  $lat = $json2->state->gps->location->lat ?? null;
  $lng = $json2->state->gps->location->lng ?? null;
  $last_u = $json2->state->last_update ?? null;
  $ultima_Conexion = $last_u ? date("d/m/Y H:i:s", strtotime($last_u)) : null;
  $speed = $json2->state->gps->speed ?? null;
  $direccion = $json2->state->gps->heading ?? null;
  $connection_status = $json2->state->connection_status ?? null;
  $movement_status = $json2->state->movement_status ?? null;
  $signal_level = $json2->state->gps->signal_level ?? null;
  $ignicion = $json2->state->inputs[0] ?? null;
  $motor = $ignicion ? 1 : 0;

  include 'odometro.php';
  include 'driver.php';

  $json = array(
    'id' => $array[$i]->id,
    'imei' => $array[$i]->source->device_id,
    'patente' => $plate,
    'lat' => $lat,
    'lng' => $lng,
    'speed' => $speed,
    'direccion' => $direccion,
    'connection_status' => $connection_status,
    'signal_level' => $signal_level,
    'movement_status' => $movement_status,
    'ignicion' => $ignicion,
    'motor' => $motor,
    'odometro' => $odometro,
    'driver' => $fullName,
    'ultima-conexion' => $ultima_Conexion,
  );

  $total[$i] = $json;

  curl_multi_remove_handle($multiCurl, $curl);
  curl_close($curl);
}

curl_multi_close($multiCurl);

include "xmlMel.php";

echo json_encode($total, http_response_code(200));

