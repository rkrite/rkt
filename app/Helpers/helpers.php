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

            $vFileDate = Carbon::now()->format('Y-m-d');
            if (preg_match('/(?:File created on|date)\s*[:=]?\s*(\d{2}\/\d{2}\/\d{4})/i', $vInitialContents, $vMatches)) {
                try {
                    $vFileDate = Carbon::createFromFormat('d/m/Y', $vMatches[1])->format('Y-m-d');
                } catch (\Exception $e) {
                }
            } else {
                try {
                    $vSubDate = substr($vInitialContents, 16, 10);
                    $vFileDate = Carbon::createFromFormat('d/m/Y', $vSubDate)->format('Y-m-d');
                } catch (\Exception $e) {
                }
            }

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
                                // Pad the centiseconds (2 digits) to milliseconds (3 digits) for Python compatibility
                                $vRowTimeMI = str_pad(substr($vRowTimeStr, 7,2), 3, "0", STR_PAD_RIGHT);
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

            // --- IRB Race Extraction ---
            $vExtractRaces = $request->input('extract_races');
            if ($vExtractRaces) {
                return GExtractVBoxRaces($vNewData, $vUploadedFileName);
            }

            return response()->streamDownload(function () use ($vNewData) {
                echo $vNewData;
            }, $vNewFileName);
        }
    } // GConvertVBoxDataFile

    // =====================================
    // =====================================
    if (!function_exists('GExtractVBoxRaces')){
        function GExtractVBoxRaces($pGPXData, $pOriginalFileName) {

            $request = request();
            $vRealRacesOnly = $request->input('real_races_only');

            // Write the full GPX to a temp file for vxd.py to process
            $vRnd       = strtolower(Str::random(8));
            $vTempDir   = storage_path('app/private/temp_files');
            $vOutDir    = $vTempDir . '/vxd-out-' . $vRnd;   // dedicated output folder per run

            $vTempGPXPath = $vTempDir . '/vbox-' . $vRnd . '.gpx';

            // Ensure the output directory exists before calling vxd.py
            @mkdir($vOutDir, 0755, true);
            file_put_contents($vTempGPXPath, $pGPXData);

            // Build the vxd.py command — pass the output dir explicitly
            $vScriptPath = base_path('app/Scripts/vxd.py');
            $vCommand    = 'python3 ' . escapeshellarg($vScriptPath)
                         . ' ' . escapeshellarg($vTempGPXPath)
                         . ' --output ' . escapeshellarg($vOutDir);
            if ($vRealRacesOnly) {
                $vCommand .= ' --real-races-only';
            }

            try {
                // Increase timeout to 5 minutes (300 seconds) for large files
                $vResult = Process::timeout(300)->run($vCommand);
                \Log::info('vxd.py output: ' . $vResult->output());
                \Log::error('vxd.py error: ' . $vResult->errorOutput());
            } catch (\Exception $e) {
                \Log::error('vxd.py process failed: ' . $e->getMessage());
                $vResult = null;
            }

            // Collect all GPX files AND the summary CSV that vxd.py wrote
            $vOutputFiles = array_merge(
                glob($vOutDir . '/*.gpx') ?: [],
                glob($vOutDir . '/summary.csv') ?: []
            );

            // We intentionally do not fall back to a raw .gpx if $vOutputFiles is empty.
            // The user requested that if the option is checked, they always receive a .zip file
            // containing at least the full GPX file.

            // Bundle all output files into a zip
            $vZipName = 'races-' . strtolower(pathinfo($pOriginalFileName, PATHINFO_FILENAME)) . '-' . $vRnd . '.zip';
            $vZipPath = $vTempDir . '/' . $vZipName;

            $vZip = new \ZipArchive();
            $vZip->open($vZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            // Add the full converted GPX first
            $vFullGPXName = 'full-' . strtolower(pathinfo($pOriginalFileName, PATHINFO_FILENAME)) . '.gpx';
            $vZip->addFile($vTempGPXPath, $vFullGPXName);
            // Add individual race tracks and summary CSV
            foreach ($vOutputFiles as $vFile) {
                $vZip->addFile($vFile, basename($vFile));
            }
            $vZip->close();


            // Read zip into memory then clean up all temp files
            $vZipContents = file_get_contents($vZipPath);
            @unlink($vTempGPXPath);
            @unlink($vZipPath);
            foreach ($vOutputFiles as $vFile) {
                @unlink($vFile);
            }
            @rmdir($vOutDir);

            return response()->streamDownload(function () use ($vZipContents) {
                echo $vZipContents;
            }, $vZipName, [
                'Content-Type' => 'application/zip',
            ]);
        }
    } // GExtractVBoxRaces


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
