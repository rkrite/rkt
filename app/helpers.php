<?php
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
