<?php

    use Illuminate\Http\Request;
    use Illuminate\Http\File;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Facades\Process;
    use Carbon\Carbon;
    use Illuminate\Support\Str;

    // =====================================
    // =====================================
    if (!function_exists('GCSVFileToArray')){
        function GCSVFileToArray($file) {

            $filename = $file->getClientOriginalName();

            // File upload location
            $location = 'storage/uploads';

            $file->move($location, $filename);
            $filepath = public_path($location."/".$filename);

            $file = fopen($filepath,"r");

            $importData_arr = array();
            $colNames = array();
            $i = 0;

            while (($filedata = fgetcsv($file, 1000, ",")) !== FALSE) {
                $num = count($filedata);
                if (!empty($filedata)){
                    if ($i == 0) {
                        $colNames = $filedata;
                    } else {
                        for ($c=0; $c < $num; $c++) {
                            $importData_arr[$i][(array_key_exists($c, $colNames)?$colNames[$c]:$c)] = $filedata [$c];
                        }
                    }
                }
                $i++;
            }
            fclose($file);
            unlink($filepath);
            return $importData_arr;
        }
    } // GCSVFileToArray

    // =====================================
    // =====================================
    if (!function_exists('GConvertVBoxDataFile')){
        function GConvertVBoxDataFile(Request $request) {

            $request->validate([
                'datasourcetype' => 'required',
                'datafile' => 'required',
                'datatype' => 'required',
            ]);

            $vDataStartTag = '[data]';
            $vDataStartTagLen = strlen($vDataStartTag) + 1;

            $vFormatType = $request->input('datatype');
            $vUploadedFileName = $request->file('datafile')->getClientOriginalName();
            $vUploadedRealPath = $request->file('datafile')->getRealPath();

            $vInitialContents = file_get_contents($vUploadedRealPath);
            $vInitialContents = str_replace("\r\n", "\n", $vInitialContents);
            $vInitialContents = str_replace(" \n", "\n", $vInitialContents);
            $vDataStartPos = strpos($vInitialContents, $vDataStartTag) + $vDataStartTagLen;
            $vDataArray = explode("\n", substr($vInitialContents, $vDataStartPos));

            $vFileDate = substr($vInitialContents, 16, 10);
            $vFileDate = Carbon::createFromFormat('d/m/Y', $vFileDate)->format('Y-m-d');

            switch ($vFormatType) {
                case 'gpx':
                default:
                    $vNewData = "";
                    $vHead = [];
                    $vHead['name'] = "VBox Data Conversion - " . $vUploadedFileName;
                    $vNewData .= GCreateGPXRecord ($vHead, 'head');

                    $vRowNum = 0;
                    foreach ($vDataArray as $vDataString) {
                        if (!empty($vDataString)){
                            $vRowNum++;
                            $vDataRow = explode(" ", $vDataString);

                            // lat
                            $vLat = $vDataRow[2];
                            if ((int)$vLat != 0) {
                                $vLatSign = substr($vLat, 0,1);
                                $vLat = substr($vLat, 1) / 60;
                                $vLat_Parts = explode(".", $vLat);
                                $vLat_deg = $vLat_Parts[0];
                                $vLat_min = ("0." . ($vLat_Parts[1]??0)) * 60;
                                $vLat_min_Parts = explode(".", $vLat_min);
                                $vLat_min = $vLat_min_Parts[0];
                                $vLat_sec = ("." . ($vLat_min_Parts[1]??0)) * 60;

                                $vLat = $vLatSign . $vLat;

                                // lon
                                $vLon = $vDataRow[3];
                                $vLonSign = substr($vLon, 0,1);
                                $vLon = substr($vLon, 1) / 60;
                                $vLon_Parts = explode(".", $vLon);
                                $vLon_deg = $vLon_Parts[0];
                                $vLon_min = ("0." . ($vLon_Parts[1]??0)) * 60;
                                $vLon_min_Parts = explode(".", $vLon_min);
                                $vLon_min = $vLon_min_Parts[0];
                                $vLon_sec = ("." . ($vLon_min_Parts[1]??00)) * 60;

                                // Time
                                $vRowTimeStr = $vDataRow[1];
                                $vRowTimeHH = substr($vRowTimeStr, 0,2);
                                $vRowTimeMM = substr($vRowTimeStr, 2,2);
                                $vRowTimeSS = substr($vRowTimeStr, 4,2);
                                $vRowTimeMI = substr($vRowTimeStr, 7,2);
                                $vRowTime = $vRowTimeHH . ":" . $vRowTimeMM . ":" . $vRowTimeSS . "." . $vRowTimeMI;

                                $vRowData = [];
                                $vRowData['lat'] = $vLat;
                                $vRowData['lon'] = $vLon;
                                $vRowData['sat'] = (int)$vDataRow[0];
                                $vRowData['ele'] = (int)$vDataRow[6];
                                $vRowData['time'] = $vFileDate . "T" .  $vRowTime . "Z";
                                $vRowData['speed'] = (int)$vDataRow[4];
                                $vRowData['course'] = (int)$vDataRow[5];
                                $vNewData .= GCreateGPXRecord ($vRowData, 'row');

                            } // latitude was captured
                        } // no empty row
                    }

                    $vNewData .= GCreateGPXRecord ('', 'foot');

                    break;
            }
            $vNewFileName = 'converted-' . strtolower($vUploadedFileName) . '-' . strtolower(Str::random(5)) . '.gpx';
            return response()->streamDownload(function () use ($vNewData) {
                echo $vNewData;
            }, $vNewFileName);
        }
    } // GConvertVBoxDataFile

    if (!function_exists('GConvertDJILogDataFile')){
        function GConvertDJILogDataFile(Request $request) {

            $request->validate([
                'datasourcetype' => 'required',
                'datafile' => 'required',
                'datatype' => 'required',
            ]);
            $vInitialContents = '';
            $vNewFile = '';
            $vConvertedRaw = false;
            $vFormatType = $request->input('datatype');
            $vUploadedFile = $request->file('datafile');
            $vUploadedFileName = $vUploadedFile->getClientOriginalName();
            $vUploadedRealPath = $vUploadedFile->getRealPath();
            $vFilePath = $vUploadedFile->store('datafiles'); // Store in 'storage/app/private/datafiles
            $vFullFilePath = Storage::disk('datafiles')->path($vFilePath);

            $vApiKey = $request->input('djiapikey');
            if (!empty($vApiKey)){
                $vRnd = Str::random(4);
                $vFullNewFile = $vFullFilePath . ".json";
                $vNewFile = $vFilePath . ".json";
                $vCommand = "./dji-log --api-key \"" . $vApiKey . "\" \"" . $vFullFilePath . "\" > " . $vFullNewFile;
                $vResult = Process::run($vCommand);
                if ($vResult->successful()){
                    $vConvertedRaw = true;
                    $vInitialContents = file_get_contents($vFullNewFile);
                } else {
                    $vInitialContents = file_get_contents($vUploadedRealPath);
                }
            } else {
                $vInitialContents = file_get_contents($vUploadedRealPath);
            }

            $vDataArray = json_decode($vInitialContents, true);

            switch ($vFormatType) {
                case 'gpx':
                default:
                    $vNewData = "";

                    $vHead = [];
                    $vHead['name'] = "DJI Log Data Conversion - " . $vUploadedFileName;
                    $vNewData .= GCreateGPXRecord ($vHead, 'head');

                    $vDataFrames = $vDataArray['frames']??[];
                    $vRowNum = 0;
                    foreach ($vDataFrames as $vFrameData) {
                        if (!empty($vFrameData)){
                            $vRowNum++;

                            $vFrameDateTime = $vFrameData['custom']['dateTime']??'';
                            $vOSD = $vFrameData['osd']??[];
                            if (!empty($vOSD)){
                                $vLat = $vOSD['latitude']??'';
                                $vLon = $vOSD['longitude']??'';
                                $vAltitude = $vOSD['altitude']??'';
                                $vSatNum = $vOSD['gpsNum']??'';
                                $vYaw = $vOSD['yaw']??'';
                                $vSpeed = sqrt(
                                    (($vOSD['xSpeed']??0) * ($vOSD['xSpeed']??0)) +
                                    (($vOSD['ySpeed']??0) * ($vOSD['ySpeed']??0)) +
                                    (($vOSD['zSpeed']??0) * ($vOSD['zSpeed']??0)));
                            }
                            // lat
                            if ((int)$vLat != 0) {

                                $vRowData = [];
                                $vRowData['lat'] = $vLat;
                                $vRowData['lon'] = $vLon;
                                $vRowData['sat'] = $vSatNum;
                                $vRowData['ele'] = $vAltitude;
                                $vRowData['time'] = $vFrameDateTime;
                                $vRowData['speed'] = $vSpeed;
                                $vRowData['course'] = $vYaw;
                                $vNewData .= GCreateGPXRecord ($vRowData, 'row');
                            } // latitude was captured
                        } // no empty row
                    }

                    $vNewData .= GCreateGPXRecord ('', 'foot');
                    break;
            }
            $vNewFileName = 'converted-' . strtolower($vUploadedFileName) . '-' . strtolower(Str::random(5)) . '.gpx';

            if (Storage::disk('datafiles')->exists($vFilePath)) {
                Storage::disk('datafiles')->delete($vFilePath);
            }
            if ($vConvertedRaw){
                if (Storage::disk('datafiles')->exists($vNewFile)) {
                    Storage::disk('datafiles')->delete($vNewFile);
                }
            }
            return response()->streamDownload(function () use ($vNewData) {
                echo $vNewData;
            }, $vNewFileName);
        }
    } // GConvertDJILogDataFile

    // =====================================
    // =====================================
    if (!function_exists('GCreateGPXRecord')){
        function GCreateGPXRecord($pData, $pType) {
            $vGPXData = "";
            switch ($pType) {
                case 'head':
                    $vGPXData = "<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"yes\"?>\n";
                    $vGPXData .= "<gpx version=\"1.1\" creator=\"Disco Octopus https://discooctopus.com/\"\n xmlns=\"http://www.topografix.com/GPX/1/1\"\n xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n xsi:schemaLocation=\"http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd http://www.garmin.com/xmlschemas/TrackPointExtension/v2 http://www.garmin.com/xmlschemas/TrackPointExtensionv2.xsd\"\n xmlns:gpxtpx=\"http://www.garmin.com/xmlschemas/TrackPointExtension/v2\">\n";
                    $vGPXData .= "    <trk>\n";
                    $vGPXData .= "        <name>" . ($pData['name']??'') . "</name>\n";
                    $vGPXData .= "        <trkseg>\n";
                    break;
                case 'foot':
                    $vGPXData .= "        </trkseg>\n";
                    $vGPXData .= "    </trk>\n";
                    $vGPXData .= "</gpx>\n";
                    break;

                case 'row':
                default:

                    $vGPXData = "            <trkpt lon=\"" . ($pData['lon']??'') . "\" lat=\"" . ($pData['lat']??'') . "\">\n";

                    // Sat
                    $vGPXData .= "                <sat>" . ($pData['sat']??'') . "</sat>" . "\n";

                    // Height
                    $vGPXData .= "                <ele>" . ($pData['ele']??'') . "</ele>" . "\n";

                    // Time
                    $vGPXData .= "                <time>" . ($pData['time']??'') . "</time>\n";

                    // Extensions
                    $vGPXData .= "                <extensions>" . "\n";
                    $vGPXData .= "                    <gpxtpx:TrackPointExtension>" . "\n";

                    // Speed
                    $vGPXData .= "                        <gpxtpx:speed>" . ($pData['speed']??'') . "</gpxtpx:speed>\n";

                    // Course
                    $vGPXData .= "                        <gpxtpx:course>" . ($pData['course']??'') . "</gpxtpx:course>\n";

                    $vGPXData .= "                    </gpxtpx:TrackPointExtension>" . "\n";
                    $vGPXData .= "                </extensions>" . "\n";
                    $vGPXData .= "            </trkpt>\n";

                    break;
            }

            return $vGPXData;

        }
    } // GCreateGPXRecord
