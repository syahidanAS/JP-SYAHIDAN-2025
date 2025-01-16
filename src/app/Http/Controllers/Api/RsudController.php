<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RsudController extends Controller
{
    protected $client;
    public function __construct()
    {
        $this->client = new Client();
    }

    public function index(Request $request)
    {
        try {
            // Ambil environment variable
            $apiUrls = [
                'connected_medfac' => env('CONNECTED_MEDICAL_FACILITY_IHS'),
                'jakarta_medfac' => env('JAKARTA_MEDICAL_FACILITY'),
                'ihs_transaction' => env('IHS_TRANSACTION')
            ];

            // Fungsi untuk melakukan permintaan API dan mengembalikan respons dalam array
            $fetchApiResponse = function ($url, $cacheKey) {
                return Cache::remember($cacheKey, 3600, function () use ($url, $cacheKey) {
                    Log::info("Cache key {$cacheKey} tidak ditemukan. Mengambil data dari API.");
                    $response = $this->client->request('GET', $url)->getBody();
                    return json_decode($response, true);
                });
            };


            // Ambil data dari API dengan caching
            $connectedMedfacData = $fetchApiResponse($apiUrls['connected_medfac'], 'connected_medfac_data');
            $jakartaMedfacData = $fetchApiResponse($apiUrls['jakarta_medfac'], 'jakarta_medfac_data');
            $ihsTransactionData = $fetchApiResponse($apiUrls['ihs_transaction'], 'ihs_transaction_data');

            // Validasi respons API
            if (!is_array($connectedMedfacData) || !is_array($jakartaMedfacData) || !is_array($ihsTransactionData)) {
                throw new \Exception('Invalid API response');
            }

            // Koleksi RS Jakarta berdasarkan organisasi_id
            $jakartaMedfacCollection = collect($jakartaMedfacData)->keyBy('organisasi_id');

            // Mapping dan merging data
            $mergedData = collect($connectedMedfacData)
                ->map(function ($item) use ($jakartaMedfacCollection) {
                    $organisasiId = (int)$item['organisasi_id'];

                    if ($jakartaMedfacCollection->has($organisasiId)) {
                        $jakartaData = $jakartaMedfacCollection->get($organisasiId);
                        $item['email'] = $jakartaData['email'] ?? null;
                        $item['kelas_rs'] = $jakartaData['kelas_rs'] ?? null;
                    }

                    return $item;
                })
                ->filter(function ($item) {
                    // Hanya data dengan email dan kelas_rs
                    return isset($item['email'], $item['kelas_rs']);
                })
                ->toArray();

            // Merge dengan data transaksi
            $finalData = collect($mergedData)
                ->map(function ($item) use ($ihsTransactionData) {
                    return $item;
                })
                ->toArray();

            // Filter berdasarkan organisasi_id jika ada
            $organisasiId = $request->organisasi_id;
            if ($organisasiId) {
                $finalData = array_filter($finalData, fn($item) => $item['organisasi_id'] == $organisasiId);
            }

            return response()->json([
                'status' => true,
                'code' => 200,
                'data' => $finalData
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'status' => false,
                'code' => 500,
                'message' => $error->getMessage()
            ], 500);
        }
    }
}
