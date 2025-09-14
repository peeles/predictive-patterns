<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Crime;
use Illuminate\Support\Arr;
use Carbon\Carbon;

class ExampleDataSeeder extends Seeder {
  public function run(): void {
    $w=-3.1; $s=53.34; $e=-2.95; $n=53.43;
    $cats = ['burglary','robbery','vehicle-crime','anti-social'];
    for ($i=0;$i<2000;$i++) {
      $lat = mt_rand($s*1e6,$n*1e6)/1e6;
      $lng = mt_rand($w*1e6,$e*1e6)/1e6;
      $h7  = '87abcdef'.dechex($i%16);
      $h6  = substr($h7,0,8).'6';
      $h8  = substr($h7,0,8).'8';

      Crime::create([
        'category' => Arr::random($cats),
        'occurred_at' => Carbon::now()->subDays(rand(0,120)),
        'lat' => $lat,
        'lng' => $lng,
        'h3_res6' => $h6,
        'h3_res7' => $h7,
        'h3_res8' => $h8,
        'raw' => null,
      ]);
    }
  }
}
