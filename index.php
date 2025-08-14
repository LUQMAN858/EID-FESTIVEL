# Build a fresh ZIP with the full working project (index.php + README.md + uploads/)
import os, zipfile, textwrap, json

project_root = "/mnt/data/dropshipper-website"
uploads_dir = os.path.join(project_root, "uploads")
os.makedirs(uploads_dir, exist_ok=True)

index_php = r"""<?php
/**
 * Dropshipper Website – Single-file PHP + SQLite
 * Frontend catalog + Admin panel (modal login) + Search + Light/Dark + Animations
 * WhatsApp order goes to: https://wa.me/message/KUCGEBF73PJ3B1
 *
 * Default Admin:
 *   Username: mluqman
 *   Password: Luqman@858
 */
declare(strict_types=1);

// -------- CONFIG -------- //
$WHATSAPP_LINK = "https://wa.me/message/KUCGEBF73PJ3B1";
$DB_FILE       = __DIR__ . "/products.db";
$UPLOADS_DIR   = __DIR__ . "/uploads";
$LOGO_PATH     = $UPLOADS_DIR . "/logo.png";
$DEFAULT_USER  = "mluqman";
$DEFAULT_PASS  = "Luqman@858";

// -------- BOOT -------- //
session_start();
if (!is_dir($UPLOADS_DIR)) { @mkdir($UPLOADS_DIR, 0775, true); }

try {
  $db = new PDO("sqlite:" . $DB_FILE);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB error: ".htmlspecialchars($e->getMessage());
  exit;
}

// tables
$db->exec("CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  price TEXT DEFAULT '',
  tags TEXT DEFAULT '',
  media TEXT DEFAULT '', -- filename in uploads
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY CHECK (id=1),
  brand TEXT NOT NULL,
  username TEXT NOT NULL,
  password TEXT NOT NULL
)");
if (!$db->query("SELECT COUNT(*) FROM settings")->fetchColumn()) {
  $db->prepare("INSERT INTO settings(id,brand,username,password) VALUES(1,?,?,?)")
     ->execute(["My Store", $DEFAULT_USER, password_hash($DEFAULT_PASS, PASSWORD_DEFAULT)]);
}
$settings = $db->query("SELECT * FROM settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$brand = $settings["brand"] ?? "My Store";

// -------- HELPERS -------- //
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function is_admin(){ return !empty($_SESSION["admin"]); }
function csrf(){
  if (empty($_SESSION["csrf"])) $_SESSION["csrf"] = bin2hex(random_bytes(16));
  return $_SESSION["csrf"];
}
function csrf_check(){
  if(!isset($_POST["csrf"]) || !hash_equals($_SESSION["csrf"] ?? "", $_POST["csrf"])) {
    http_response_code(403); exit("Invalid CSRF");
  }
}
function file_save($f){
  global $UPLOADS_DIR;
  if(!isset($f["tmp_name"]) || !is_uploaded_file($f["tmp_name"])) return "";
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($f["tmp_name"]);
  $ok = [
    "image/jpeg"=>".jpg","image/png"=>".png","image/webp"=>".webp","image/gif"=>".gif",
    "video/mp4"=>".mp4","video/webm"=>".webm"
  ];
  if(!isset($ok[$mime])) return "";
  $name = uniqid("m_", true).$ok[$mime];
  $dest = $UPLOADS_DIR."/".$name;
  if(!move_uploaded_file($f["tmp_name"], $dest)) return "";
  return $name;
}
function product($db,$id){ $st=$db->prepare("SELECT * FROM products WHERE id=?"); $st->execute([(int)$id]); return $st->fetch(PDO::FETCH_ASSOC); }
function products($db,$q){
  if($q!==""){
    $st=$db->prepare("SELECT * FROM products WHERE title LIKE ? OR description LIKE ? OR tags LIKE ? ORDER BY created_at DESC");
    $like="%$q%"; $st->execute([$like,$like,$like]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
  return $db->query("SELECT * FROM products ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}
function wa_link($title){ global $WHATSAPP_LINK; return $WHATSAPP_LINK . "?text=" . rawurlencode("I want to order: ".$title); }

// -------- AUTH -------- //
if(isset($_GET["logout"])){ session_destroy(); header("Location:?"); exit; }
if(isset($_POST["login"])){
  $u = trim($_POST["username"] ?? "");
  $p = $_POST["password"] ?? "";
  if($u === ($GLOBALS['settings']["username"] ?? "") && password_verify($p, $GLOBALS['settings']["password"] ?? "")) {
    $_SESSION["admin"] = true; header("Location: ?admin=dashboard"); exit;
  } else { $login_error = "Wrong username or password"; }
}

// -------- SETTINGS SAVE -------- //
if(is_admin() && isset($_POST["save_settings"])){
  csrf_check();
  $brand_in = trim($_POST["brand"] ?? $brand);
  if(!empty($_FILES["logo"]["name"])) { @move_uploaded_file($_FILES["logo"]["tmp_name"], $LOGO_PATH); }
  if(!empty($_POST["newpass"])) {
    $hash = password_hash($_POST["newpass"], PASSWORD_DEFAULT);
    $db->prepare("UPDATE settings SET brand=?, password=? WHERE id=1")->execute([$brand_in,$hash]);
  } else {
    $db->prepare("UPDATE settings SET brand=? WHERE id=1")->execute([$brand_in]);
  }
  header("Location: ?admin=settings&ok=1"); exit;
}

// -------- PRODUCTS CRUD -------- //
if(is_admin() && isset($_POST["save_product"])) {
  csrf_check();
  $id    = (int)($_POST["id"] ?? 0);
  $title = trim($_POST["title"] ?? "");
  $desc  = trim($_POST["description"] ?? "");
  $price = trim($_POST["price"] ?? "");
  $tags  = trim($_POST["tags"] ?? "");
  $keep  = trim($_POST["media_existing"] ?? "");
  $new   = (!empty($_FILES["media"]["name"])) ? file_save($_FILES["media"]) : "";
  $media = $new ?: $keep;
  if($title && $desc){
    if($id>0){
      $db->prepare("UPDATE products SET title=?,description=?,price=?,tags=?,media=? WHERE id=?")->execute([$title,$desc,$price,$tags,$media,$id]);
      header("Location: ?admin=dashboard&flash=updated"); exit;
    } else {
      $db->prepare("INSERT INTO products(title,description,price,tags,media) VALUES(?,?,?,?,?)")->execute([$title,$desc,$price,$tags,$media]);
      header("Location: ?admin=dashboard&flash=created"); exit;
    }
  } else { $form_error="Please fill title and description."; }
}
if(is_admin() && isset($_GET["del"])) {
  $db->prepare("DELETE FROM products WHERE id=?")->execute([(int)$_GET["del"]]);
  header("Location: ?admin=dashboard&flash=deleted"); exit;
}

// -------- ROUTING -------- //
$q = trim($_GET["q"] ?? "");
$admin_view = $_GET["admin"] ?? "";
$view = $_GET["view"] ?? "home";
$id   = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
$open_login_modal = (!is_admin() && $admin_view === "login");
?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($brand)?></title>
<style>
:root{ --bg:#0b1020; --card:#121a33; --muted:#94a3b8; --text:#e6ecff; --accent:#3b82f6; --acc2:#10b981; --danger:#ef4444; --warning:#f59e0b; --ring:#60a5fa; --border: rgba(255,255,255,.10); }
:root[data-theme='light']{ --bg:#f7f8fc; --card:#ffffff; --text:#0b1020; --muted:#5b6478; --border: rgba(10,20,40,.10); }
*{box-sizing:border-box} body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text);}
a{text-decoration:none;color:inherit}
.container{max-width:1150px;margin:0 auto;padding:18px}
header.nav{position:sticky;top:0;z-index:40;background:rgba(16,22,46,.6);backdrop-filter:blur(8px);border-bottom:1px solid var(--border);animation:navIn .5s ease both}
:root[data-theme='light'] header.nav{background:rgba(255,255,255,.7)}
@keyframes navIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
.brand{display:flex;align-items:center;gap:10px;font-weight:800}
.logo{width:36px;height:36px;border-radius:12px;background:conic-gradient(from 45deg,var(--accent),var(--acc2));box-shadow:0 0 0 4px rgba(59,130,246,.15)}
.logo-img{height:36px;border-radius:10px}
.searchbar{display:flex;gap:10px;margin:12px 0}
input[type=text],input[type=number],input[type=password],textarea,select{ width:100%;padding:12px 14px;border:1px solid var(--border);background:transparent;color:var(--text); border-radius:14px;outline:none;transition:.2s }
input:focus,textarea:focus,select:focus{border-color:var(--ring);box-shadow:0 0 0 4px rgba(96,165,250,.15)}
.grid{display:grid;grid-template-columns:repeat(1,minmax(0,1fr));gap:16px}
@media(min-width:640px){.grid{grid-template-columns:repeat(2,1fr)}}
@media(min-width:980px){.grid{grid-template-columns:repeat(3,1fr)}}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.12);animation:cardIn .45s ease both}
@keyframes cardIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.media{position:relative;width:100%;aspect-ratio:1/1;display:block;background:#0d1330}
:root[data-theme='light'] .media{background:#f0f3ff}
.media img,.media video{width:100%;height:100%;object-fit:cover}
.p{padding:14px}
.title{font-weight:700;font-size:1.05rem}
.muted{color:var(--muted)}
price{font-weight:800;margin-top:8px;display:block}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:14px;border:2px solid currentColor;position:relative;overflow:hidden;cursor:pointer;transition:transform .08s ease, filter .2s}
.btn:active{transform:scale(.97)} .btn .rip{position:absolute;inset:0;background:currentColor;opacity:.12;transform:translateY(100%);transition:transform .25s ease}
.btn:hover .rip{transform:translateY(0)}
.btn-primary{color:var(--accent)} .btn-success{color:var(--acc2)} .btn-danger{color:var(--danger)} .btn-warn{color:var(--warning)}
.flex{display:flex;gap:12px;align-items:center} .between{justify-content:space-between} .wrap{flex-wrap:wrap}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid var(--border);font-size:.85rem}
.hero{display:grid;gap:10px;grid-template-columns:1fr} @media(min-width:900px){.hero{grid-template-columns:1fr 1fr}}
.footer{color:#9fb0d0;padding:40px 18px;text-align:center}
.adminnav{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0}
.theme-toggle{position:fixed;right:14px;bottom:14px;z-index:50}
/* Modal */
#modalBackdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);backdrop-filter:blur(6px);display:none;z-index:60}
#loginModal{position:fixed;inset:0;display:none;z-index:70;place-items:center}
.modal-card{width:min(92vw,440px);border-radius:22px;border:1px solid var(--border);background:linear-gradient(120deg,rgba(255,255,255,.06),rgba(255,255,255,.02));backdrop-filter:blur(14px);box-shadow:0 20px 70px rgba(0,0,0,.35);transform:scale(.92);opacity:0;animation:modalIn .35s ease forwards}
:root[data-theme='light'] .modal-card{background:linear-gradient(120deg,rgba(255,255,255,.75),rgba(255,255,255,.65))}
@keyframes modalIn{to{opacity:1;transform:scale(1)}}
.modal-header{padding:16px 18px;border-bottom:1px solid var(--border);font-weight:800}
.modal-body{padding:18px}
.modal-actions{display:flex;justify-content:space-between;gap:10px;padding:0 18px 18px 18px}
hr.sep{border:none;border-top:1px solid var(--border);margin:14px 0}
</style>
<script>
function setTheme(t){ document.documentElement.setAttribute('data-theme', t); localStorage.setItem('theme', t); }
function toggleTheme(){ const cur = document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark'; setTheme(cur); }
document.addEventListener('DOMContentLoaded', ()=>{ setTheme(localStorage.getItem('theme')||'light'); if(<?=json_encode($open_login_modal)?>){ openLogin(); } });
function openLogin(){ document.getElementById('modalBackdrop').style.display='block'; document.getElementById('loginModal').style.display='grid'; }
function closeLogin(){ document.getElementById('modalBackdrop').style.display='none'; document.getElementById('loginModal').style.display='none'; }
</script>
</head>
<body>
<header class="nav">
  <div class="container flex between wrap">
    <a class="brand" href="?">
      <?php if (file_exists($LOGO_PATH)): ?>
        <img src="uploads/<?=basename($LOGO_PATH)?>" class="logo-img" alt="logo">
      <?php else: ?>
        <span class="logo"></span>
      <?php endif; ?>
      <span><?=h($brand)?></span>
    </a>
    <nav class="flex wrap" style="gap:10px">
      <a class="btn btn-primary" href="?"><span class="rip"></span>Home</a>
      <a class="btn btn-primary" href="?view=all"><span class="rip"></span>All Products</a>
      <?php if(!is_admin()): ?>
        <button class="btn btn-success" onclick="openLogin()"><span class="rip"></span>Admin</button>
      <?php else: ?>
        <a class="btn btn-success" href="?admin=dashboard"><span class="rip"></span>Dashboard</a>
      <?php endif; ?>
      <button class="btn btn-primary" onclick="toggleTheme()"><span class="rip"></span>🌗 Theme</button>
    </nav>
  </div>
</header>

<main class="container">
  <?php if(!$admin_view): ?>
    <form class="searchbar" method="get" action="?">
      <input type="text" name="q" placeholder="Search products..." value="<?=h($q)?>">
      <button class="btn btn-primary" type="submit"><span class="rip"></span>Search</button>
    </form>

    <?php if($view==='home'){ ?>
      <section class="hero">
        <div class="card p">
          <h2 style="margin:8px 0 4px 0">Welcome to <?=h($brand)?></h2>
          <p class="muted">Explore trending products. Click a product to see details. Order goes straight to WhatsApp.</p>
          <div class="flex wrap" style="margin-top:8px">
            <a class="btn btn-success" href="?view=all"><span class="rip"></span>Browse All</a>
            <a class="btn btn-primary" href="<?=h($WHATSAPP_LINK)?>" target="_blank" rel="noopener"><span class="rip"></span>Chat on WhatsApp</a>
          </div>
        </div>
      </section>
    <?php } ?>

    <?php if($view==='all' || $view==='home' || $q!==''){ $items = products($db,$q); ?>
      <section style="margin-top:18px">
        <?php if($q!==''){ ?><div class="muted">Showing results for: <span class="badge"><?=h($q)?></span></div><?php } ?>
        <div class="grid" style="margin-top:10px">
        <?php if(!$items){ echo '<div class="muted">No products found.</div>'; } ?>
        <?php foreach($items as $it): ?>
          <article class="card">
            <div class="media">
              <?php if($it['media'] && preg_match('/\.(mp4|webm)$/i',$it['media'])): ?>
                <video src="uploads/<?=h($it['media'])?>" autoplay muted loop playsinline></video>
              <?php elseif($it['media']): ?>
                <img src="uploads/<?=h($it['media'])?>" alt="<?=h($it['title'])?>">
              <?php else: ?>
                <div style="display:grid;place-items:center;height:100%;color:#94a3b8">No Media</div>
              <?php endif; ?>
            </div>
            <div class="p">
              <div class="title"><?=h($it['title'])?></div>
              <?php if($it['price']!==''){ ?><price>PKR <?=h($it['price'])?></price><?php } ?>
              <div class="muted" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?=h($it['description'])?></div>
              <div class="flex between" style="margin-top:10px">
                <div><?php if($it['tags']!==''){ ?><span class="badge">#<?=h($it['tags'])?></span><?php } ?></div>
                <div class="flex">
                  <a class="btn btn-primary" href="?view=detail&id=<?=$it['id']?>"><span class="rip"></span>View</a>
                  <a class="btn btn-success" href="<?=h(wa_link($it['title']))?>" target="_blank" rel="noopener"><span class="rip"></span>Order Now</a>
                </div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
      </section>
    <?php } ?>

    <?php if($view==='detail' && $id>0){ $p=product($db,$id); if($p){ ?>
      <section style="margin-top:18px">
        <article class="card">
          <div class="media">
            <?php if($p['media'] && preg_match('/\.(mp4|webm)$/i',$p['media'])): ?>
              <video src="uploads/<?=h($p['media'])?>" autoplay muted loop playsinline></video>
            <?php elseif($p['media']): ?>
              <img src="uploads/<?=h($p['media'])?>" alt="<?=h($p['title'])?>">
            <?php endif; ?>
          </div>
          <div class="p">
            <h2 class="title" style="font-size:1.3rem"><?=h($p['title'])?></h2>
            <?php if($p['price']!==''){ ?><price>PKR <?=h($p['price'])?></price><?php } ?>
            <p><?=nl2br(h($p['description']))?></p>
            <div class="flex between wrap" style="margin-top:10px">
              <div><?php if($p['tags']!==''){ ?><span class="badge">#<?=h($p['tags'])?></span><?php } ?></div>
              <a class="btn btn-success" href="<?=h(wa_link($p['title']))?>" target="_blank" rel="noopener"><span class="rip"></span>Order on WhatsApp</a>
            </div>
          </div>
        </article>
      </section>
    <?php } else { echo '<div class="muted">Product not found.</div>'; } } ?>

  <?php else: // ----- ADMIN VIEWS ------ ?>
    <?php if(!is_admin() && $admin_view): ?>
      <div class="card" style="max-width:420px;margin:40px auto">
        <div class="p"><h3>Admin Login</h3></div><hr class="sep">
        <?php if(!empty($login_error)): ?><div class="p" style="color:#ef4444"><?=h($login_error)?></div><?php endif; ?>
        <form class="p" method="post">
          <input type="hidden" name="login" value="1">
          <div style="display:grid;gap:10px">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <div class="flex between">
              <a class="btn btn-primary" href="?" ><span class="rip"></span>← Back</a>
              <button class="btn btn-success" type="submit"><span class="rip"></span>Login</button>
            </div>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <?php if(is_admin()): ?>
      <div class="card p">
        <div class="adminnav">
          <a class="btn btn-primary" href="?admin=dashboard"><span class="rip"></span>Dashboard</a>
          <a class="btn btn-primary" href="?admin=new"><span class="rip"></span>Add Product</a>
          <a class="btn btn-primary" href="?admin=settings"><span class="rip"></span>Settings</a>
          <a class="btn btn-danger" href="?logout=1"><span class="rip"></span>Logout</a>
        </div>
      </div>

      <?php if($admin_view==='dashboard' || $admin_view===''): $items = products($db,""); ?>
        <section style="margin-top:14px">
          <div class="grid">
            <?php foreach($items as $it): ?>
              <article class="card">
                <div class="media">
                  <?php if($it['media'] && preg_match('/\.(mp4|webm)$/i',$it['media'])): ?>
                    <video src="uploads/<?=h($it['media'])?>" autoplay muted loop playsinline></video>
                  <?php elseif($it['media']): ?>
                    <img src="uploads/<?=h($it['media'])?>" alt="<?=h($it['title'])?>">
                  <?php else: ?>
                    <div style="display:grid;place-items:center;height:100%;color:#94a3b8">No Media</div>
                  <?php endif; ?>
                </div>
                <div class="p">
                  <div class="title"><?=h($it['title'])?></div>
                  <div class="muted"><?=h($it['price']!==''?'PKR '.$it['price']:'No price')?></div>
                  <div class="flex between" style="margin-top:10px">
                    <div class="flex">
                      <a class="btn btn-primary" href="?admin=edit&id=<?=$it['id']?>"><span class="rip"></span>Edit</a>
                      <a class="btn btn-danger" href="?admin=dashboard&del=<?=$it['id']?>" onclick="return confirm('Delete this product?')"><span class="rip"></span>Delete</a>
                    </div>
                    <a class="btn btn-success" href="?view=detail&id=<?=$it['id']?>"><span class="rip"></span>Preview</a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if(!$items) echo '<div class="muted" style="margin-top:10px">No products yet. Click "Add Product".</div>'; ?>
        </section>
      <?php endif; ?>

      <?php if($admin_view==='new' || ($admin_view==='edit' && $id>0)): $p = $admin_view==='edit'?product($db,$id):null; ?>
        <div class="card p" style="margin-top:14px">
          <h3 style="margin:6px 0"><?= $p?'Edit':'Add' ?> Product</h3>
          <?php if(!empty($form_error)): ?><div style="color:#ef4444;margin:6px 0"><?=h($form_error)?></div><?php endif; ?>
          <form method="post" enctype="multipart/form-data" style="display:grid;gap:10px">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
            <?php if($p){ ?><input type="hidden" name="id" value="<?=$p['id']?>"><?php } ?>
            <input type="text" name="title" placeholder="Title" value="<?=h($p['title'] ?? '')?>" required>
            <textarea name="description" placeholder="Description" rows="4" required><?=h($p['description'] ?? '')?></textarea>
            <input type="text" name="price" placeholder="Price (PKR)" value="<?=h($p['price'] ?? '')?>">
            <input type="text" name="tags" placeholder="Tags (comma separated)" value="<?=h($p['tags'] ?? '')?>">
            <?php if(!empty($p['media'])): ?>
              <div class="muted">Current media: <span class="badge"><?=h($p['media'])?></span></div>
              <input type="hidden" name="media_existing" value="<?=h($p['media'])?>">
            <?php endif; ?>
            <input type="file" name="media" accept="image/*,video/mp4,video/webm">
            <div class="flex between">
              <a class="btn btn-primary" href="?admin=dashboard"><span class="rip"></span>Cancel</a>
              <button class="btn btn-success" type="submit" name="save_product" value="1"><span class="rip"></span>Save</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if($admin_view==='settings'): ?>
        <div class="card p" style="margin-top:14px;max-width:640px">
          <?php if(isset($_GET['ok'])) echo '<div class="badge">Saved ✓</div>'; ?>
          <h3>Settings</h3>
          <form method="post" enctype="multipart/form-data" style="display:grid;gap:10px">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
            <label>Brand Name
              <input type="text" name="brand" value="<?=h($brand)?>" required>
            </label>
            <label>Logo (PNG)
              <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
            </label>
            <label>New Admin Password (optional)
              <input type="password" name="newpass" placeholder="Leave blank to keep">
            </label>
            <div class="flex between">
              <a class="btn btn-primary" href="?admin=dashboard"><span class="rip"></span>Back</a>
              <button class="btn btn-success" type="submit" name="save_settings" value="1"><span class="rip"></span>Save</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  <?php endif; ?>
</main>

<footer class="footer">© <?=date('Y')?> <?=h($brand)?> • Built for fast dropshipping demos</footer>

<!-- LOGIN MODAL (front-page quick admin) -->
<div id="modalBackdrop" onclick="closeLogin()"></div>
<div id="loginModal">
  <div class="modal-card">
    <div class="modal-header">Admin Login</div>
    <div class="modal-body">
      <?php if(!empty($login_error)): ?><div style="color:#ef4444"><?=h($login_error)?></div><?php endif; ?>
      <form method="post" style="display:grid;gap:10px">
        <input type="hidden" name="login" value="1">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
      </form>
    </div>
    <div class="modal-actions">
      <button class="btn btn-warn" onclick="closeLogin()"><span class="rip"></span>Close</button>
      <button class="btn btn-success" onclick="document.querySelector('#loginModal form').submit()"><span class="rip"></span>Login</button>
    </div>
  </div>
</div>
<script>
// open modal via query (?admin=login) already handled; keep extra helper:
</script>
</body>
</html>
"""

readme = """# Dropshipper Website (PHP + SQLite, Single-file)
Fully working, mobile-friendly catalog with Admin panel, search, light/dark theme, animations, and WhatsApp ordering.

## Files
- `index.php` – Frontend + Admin (single file)
- `uploads/` – media directory (images/videos/logo)

## Admin Login
- Username: `mluqman`
- Password: `Luqman@858`

## Features
- Add/Edit/Delete products with image/video upload
- Search by title/description/tags
- Light/Dark theme (persists)
- Animated cards, ripple buttons, glassy modal login
- Brand & logo (in Settings)
- WhatsApp “Order Now” opens chat with product name

## Setup
1. Upload files to any PHP hosting (PHP 7.4+).  
2. Ensure the server can write to the project folder and `uploads/`.  
3. Open site → DB auto-creates.  
4. Click **Admin** → login → add products.
"""

# Write files
with open(os.path.join(project_root, "index.php"), "w", encoding="utf-8") as f:
    f.write(index_php)

with open(os.path.join(project_root, "README.md"), "w", encoding="utf-8") as f:
    f.write(readme)

# Zip it
zip_path = "/mnt/data/dropshipper-website.zip"
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(project_root):
        for file in files:
            full = os.path.join(root, file)
            arc = os.path.relpath(full, project_root)
            z.write(full, arc)

zip_path
