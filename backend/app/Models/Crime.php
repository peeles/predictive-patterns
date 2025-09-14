<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Crime extends Model {
  public $incrementing = false;
  protected $keyType = 'string';
  protected $fillable = ['id','category','occurred_at','lat','lng','h3_res6','h3_res7','h3_res8','raw'];
  protected $casts = ['occurred_at'=>'datetime','raw'=>'array'];

  protected static function booted(): void {
    static::creating(function ($m) {
      if (!$m->id) $m->id = (string) Str::uuid();
    });
  }
}
