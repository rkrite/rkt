@include('layouts.head')
    <body class="m-5 text-blueish">
        <h1 class="text-2xl font-bold">Data Converter</h1>

        <form method="POST" enctype="multipart/form-data" action="{{ route('data.convert')  }}">
            @csrf
            <div class="mt-2">
                <p class="mt-3">Convert...
                    <ul class="ml-5">
                        <li>raw VBox ".vbo" files into ".gpx" data files.</li>
                        <li>raw DJI Flight Log ".json" files into ".gpx" data files.</li>
                    </ul>
                </p>

                <div class="mt-10">

                    <label for="datasourcetype" class="text-sm/6">Choose a data source type</label>
                    <div class="mt-2 mb-7">
                        <select id="datasourcetype" name="datasourcetype" autocomplete="datasourcetype-name" class="w-full appearance-none rounded-md py-1.5 pl-3 text-redish outline-1 outline-redish focus:outline-2 sm:text-sm/6">
                            <option value="">select...</option>
                            <option value="vbox" selected>VBox</option>
                            <option value="djilog">DJI Flight Log</option>
                        </select>
                    </div>

                    <label for="datafile" class="text-sm/6">Choose a VBox data file</label>
                    <div class="mt-2 mb-5">
                        <div class="rounded-md pl-3 outline-1 outline-redish focus-within:outline-2">
                            <input id="datafile" type="file" name="datafile" placeholder="datafile" class="py-1.5 pl-1 text-base text-redish placeholder:text-redish focus:outline-none sm:text-sm/6" />
                        </div>
                    </div>

                    <label for="datatype" class="text-sm/6">Choose a data format</label>
                    <div class="mt-2 mb-7">
                        <select id="datatype" name="datatype" autocomplete="datatype-name" class="w-full appearance-none rounded-md py-1.5 pl-3 text-redish outline-1 outline-redish focus:outline-2 sm:text-sm/6">
                            <option value="">select...</option>
                            <option value="gpx" selected>GPX</option>
                            <option value="kml">KML</option>
                        </select>
                    </div>
                </div>
                <div class="mt-6 flex items-center gap-x-4">
                    <button type="reset" alt="Clear the form" class="text-sm/6 pl-2 pr-2 pt-1 pb-1 rounded-md outline-1 outline-redish font-semibold text-greyish focus:outline-2 hover:bg-lightgrey">Cancel</button>
                    <button type="submit" class="text-sm/6 pl-2 pr-2 pt-1 pb-1 rounded-md outline-1 outline-redish font-semibold text-blueish focus:outline-2 hover:bg-lightgrey">Submit</button>
                </div>
                @if (isset($newdata))
                    <div>
                        <pre>{{ $newdata }}</pre>
                    </div>
                @endif

            </div>
        </form>
@include('layouts.footer')
    </body>
@include('layouts.foot')
