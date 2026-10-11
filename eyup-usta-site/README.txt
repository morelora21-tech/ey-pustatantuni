EYÜP USTA TANTUNİ - SİTE VE YÖNETİM PANELİ
==========================================

KURULUM
1) Bu klasördeki her şeyi (index.html, admin.php, content.json, defaults.json,
   uploads/, videolar/, data/) sitenizin ana klasörüne yükleyin (örn. public_html).
2) uploads, videolar ve data klasörlerine ve content.json dosyasına yazma izni verin
   (çoğu hostingde zaten vardır; gerekirse klasörler 755 veya 775, content.json 664).
3) Tarayıcıdan  siteadresiniz.com/admin.php  açın. İlk girişte kendi şifrenizi
   belirleyin (en az 8 karakter).
4) Yazıları, fotoğrafları, videoları değiştirin ve KAYDET'e basın. Değişiklik hemen yayında.

GEREKSİNİM
PHP 7.4 veya üzeri (çoğu hosting paketinde var). Veritabanı gerekmez.
Site dosyası artık index.html adını taşıyor (eski adı eyüpustatantuni.html).

PANELDE NELER YAPILIR
- Yazılar: sitedeki tüm metinler, 9 dilin her biri için ayrı ayrı düzenlenir. "↺ sıfırla" eski haline döndürür.
- Fotoğraflar: ekle, sil, sıra değiştir, başlık yaz.
- Videolar: ekle, sil, sıra değiştir.
- Ayarlar: telefon numaraları, WhatsApp numarası, harita linki, şifre değiştirme.

NOTLAR
- Video yükleme boyutu hostingin sınırına bağlıdır (panelde yazar). Büyük videoyu önce küçültün.
- Şifreyi unutursanız data/auth.php dosyasını silin; admin.php yeniden şifre sorar.
- Yedek için content.json, uploads ve videolar klasörlerini saklayın.
- Nginx kullanıyorsanız hosting desteğinden data/ klasörünü dışarıya kapatmasını isteyin
  (Apache'de data/.htaccess bunu zaten yapar).
