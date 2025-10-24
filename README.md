# TCKN Verifier – WHMCS Addon

## Kurulum
1. Bu klasörü `modules/addons/tcknverifier/` altına yükleyin.
2. WHMCS Admin → System Settings → Addon Modules → **TCKN Verifier** → Activate.
   - Aktivasyon, mevcutsa `TC Kimlik No` / `T.C Kimlik No` alanını bulur; yoksa oluşturur ve FieldId'yi ayarlara yazar.
3. Ayarlardan:
   - Enforce Uniqueness: ON
   - Immutable After Create: ON
   - Allow Client Edit: OFF
   - Allow Admin Edit: ON
   - Show “Registered” Badge: ON
   - Debug Log Activity: (isteğe bağlı) ON

## Notlar
- TCKN algoritması (11 hane, 10. ve 11. kontrol basamakları) uygulanır.
- Tekillik kontrolü, verileri normalize ederek yapılır (boşluk/noktalama yok sayılır).
- Profilde geçerli TCKN yanında “✔️ Kayıtlı” rozeti ve read-only + bilgi mesajı görünür.
- Şablon dosyası değişikliği gerekmez.
