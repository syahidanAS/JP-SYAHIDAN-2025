<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RsudControllerFilter extends Controller
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
                        $item['kota_kab'] = $jakartaData['kota_kab'] ?? null;
                    }

                    return $item;
                })
                ->filter(function ($item) {
                    // Hanya data dengan email, kelas_rs, dan kota_kab
                    return isset($item['email'], $item['kelas_rs'], $item['kota_kab']);
                })
                ->toArray();

            // Merge dengan data transaksi
            $finalData = collect($mergedData)
                ->map(function ($item) use ($ihsTransactionData) {
                    $organisasiId = $item['organisasi_id'];
                    $filteredTransactions = array_values(
                        array_filter($ihsTransactionData, fn($data) => $data['organisasi_id'] == $organisasiId)
                    );
                    $item['total_jumlah_pengiriman_data'] = array_sum(array_column($filteredTransactions, 'jumlah_pengiriman_data'));

                    return $item;
                })
                ->toArray();

            // Filter berdasarkan organisasi_id jika ada
            $organisasiId = $request->organisasi_id;
            if ($organisasiId) {
                $finalData = array_filter($finalData, fn($item) => $item['organisasi_id'] == $organisasiId);
            }

            // Filter tambahan berdasarkan kelas_rs dan kota_kab
            $kelasRs = $request->kelas_rs;
            $kotaKab = $request->kota_kab;


            if ($kelasRs) {
                $finalData = array_filter($finalData, fn($item) => $item['kelas_rs'] == $kelasRs);
            }

            if ($kotaKab) {
                $finalData = array_filter($finalData, fn($item) => $item['kota_kab'] == $kotaKab);
            }

            return response()->json([
                'status' => true,
                'code' => 200,
                'data' => array_values($finalData) // Pastikan array tetap memiliki indeks numerik
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
