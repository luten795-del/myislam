<?php
declare(strict_types=1);
session_start();

// Set default language if not set
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';

// Redirect logged-in users
if (isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit;
}

// Simple translation function for landing page
function lt(string $key): string {
    $lang = $_SESSION['language'] ?? 'en';
    
    $translations = [
        'en' => [
            'platform_title' => 'MyIslam Learning Platform',
            'hero_title1' => 'Learn Islam',
            'hero_title2' => 'through MyIslam',
            'hero_desc' => 'Your comprehensive platform for Islamic education. Learn Quran, Hadith, Fiqh, and more. Track your progress, take quizzes, earn certificates, and grow in knowledge.',
            'get_started' => 'Start',
            'learn_more' => 'Learn More',
            'features' => 'Features',
            'about' => 'About',
            'login' => 'Login',
            'join_now' => 'Join Now',
            'what_we_offer' => 'What We Offer',
            'what_we_offer_desc' => 'Everything you need for your Islamic learning journey',
            'active_students' => 'Active Students',
            'subjects' => 'Subjects',
            'quizzes' => 'Quizzes',
            'certifications' => 'Certifications',
            'feature1_title' => 'Structured Lessons',
            'feature1_desc' => 'Well-organized content with Arabic text, translations, references, and rich media support including audio and video.',
            'feature2_title' => 'Interactive Quizzes',
            'feature2_desc' => 'Test your knowledge with lesson quizzes, self-practice, competitions, and monthly exams with instant results.',
            'feature3_title' => 'Leaderboard Rankings',
            'feature3_desc' => 'Compete with fellow students across different modes. Track your rank and strive for the top position.',
            'feature4_title' => 'Certifications',
            'feature4_desc' => 'Earn certificates by passing certification exams. Showcase your achievements and knowledge.',
            'feature5_title' => 'Progress Tracking',
            'feature5_desc' => 'Monitor your learning progress, streaks, completed lessons, and time spent studying.',
            'feature6_title' => 'Multimedia Library',
            'feature6_desc' => 'Access a rich collection of Islamic media including audio lectures, videos, and images.',
            'about_title' => 'About MyIslam',
            'about_desc1' => 'MyIslam is dedicated to providing quality Islamic education to all muslims around the world . Our platform offers structured learning paths, interactive assessments, and a supportive community to help you grow in your Islamic knowledge.',
            'about_desc2' => 'Join hundreds of students already learning and growing with us. Whether you\'re a beginner or advanced learner, there\'s something for everyone.',
            'full_name' => 'MyIslam - Islamic Learning Platform',
            'footer_name' => 'MyIslam - Islamic Learning Platform',
            'footer_location' => 'Masjid CityMall - Dar es Salaam',
            'footer_rights' => 'All rights reserved. Developed & Managed by LUTEN CANAL SHARIFU',
            'nav_features' => 'Features',
            'nav_about' => 'About',
            'language' => 'EN',
        ],
        'sw' => [
            'platform_title' => 'Jukwaa la Elimu ya Kiislamu - MyIslam',
            'hero_title1' => 'jifunze Uislam ',
            'hero_title2' => 'kupitia MyIslam',
            'hero_desc' => 'Jukwaa lako kamili la elimu ya Kiislamu. Jifunze Quran, Hadith, Fiqh, na zaidi. Fuatilia maendeleo yako, fanya mitihani, pata vyeti, na kua katika elimu.',
            'get_started' => 'Anza sasa',
            'learn_more' => 'Jifunze Zaidi',
            'features' => 'Vipengele',
            'about' => 'Kuhusu',
            'login' => 'Ingia',
            'join_now' => 'Jiunge Sasa',
            'what_we_offer' => 'Tunachotoa',
            'what_we_offer_desc' => 'Kila kitu unachohitaji katika kujifunza Uislamu',
            'active_students' => 'Wanafunzi',
            'subjects' => 'Masomo',
            'quizzes' => 'Mitihani',
            'certifications' => 'Vyeti',
            'feature1_title' => 'Masomo Yaliyopangwa',
            'feature1_desc' => 'Maudhui yaliyopangwa vizuri na maandishi ya Kiarabu, tafsiri, marejeleo, na vyombo vya habari ikiwemo sauti na video.',
            'feature2_title' => 'Mitihani Shirikishi',
            'feature2_desc' => 'Jaribu ujuzi wako kwa mitihani ya masomo, mazoezi binafsi, mashindano, na mitihani ya kila mwezi kwa matokeo ya papo hapo.',
            'feature3_title' => 'Nafasi za Uongozi',
            'feature3_desc' => 'Shindana na wanafunzi wenzako katika aina mbalimbali. Fuatilia nafasi yako na jitahidi kufika juu.',
            'feature4_title' => 'Vyeti',
            'feature4_desc' => 'Pata vyeti kwa kufaulu mitihani ya vyeti. Onyesha mafanikio yako na ujuzi wako.',
            'feature5_title' => 'Ufuatiliaji wa Maendeleo',
            'feature5_desc' => 'Fuatilia maendeleo yako ya kujifunza, siku za mfululizo, masomo yaliyokamilika, na muda uliotumika kusoma.',
            'feature6_title' => 'Maktaba ya Multimedia',
            'feature6_desc' => 'Pata mkusanyiko mkubwa wa vyombo vya habari vya Kiislamu ikiwemo mihadhara ya sauti, video, na picha.',
            'about_title' => 'Kuhusu MyIslam',
            'about_desc1' => 'MyIslam ni jukwaa  maalum la mafundisho ya Uislamu kwa waislam wote Duniani. Jukwaa letu linatoa njia zilizopangwa za kujifunza, tathmini shirikishi, na jamii inayosaidia kukusaidia kukua katika elimu yako ya Kiislamu.',
            'about_desc2' => 'Jiunge na mamia ya waislam ambao tayari wanajifunza na kukua nasi. Iwe wewe ni mwanafunzi wa mwanzo au wa juu, kuna kitu kwa kila mtu.',
            'full_name' => 'MyIslam - Jukwaa la Elimu ya Kiislamu',
            'footer_name' => 'MyIslam - Jukwaa la Elimu ya Kiislamu',
            'footer_location' => 'Masjid CityMall - Dar es Salaam',
            'footer_rights' => 'Haki zote zimehifadhiwa. Imetengenezwa na Kusimamiwa na LUTEN CANAL SHARIFU',
            'nav_features' => 'Vipengele',
            'nav_about' => 'Kuhusu',
            'language' => 'SW',
        ]
    ];
    
    return $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;
}

// Fetch stats from database
$stats = ['students' => 0, 'subjects' => 0, 'quizzes' => 0, 'certificates' => 0];
try {
    $conn = db();
    if ($conn) {
        $stats['students'] = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND is_active = 1")->fetchColumn();
        $stats['subjects'] = (int)$conn->query("SELECT COUNT(*) FROM subjects WHERE is_active = 1")->fetchColumn();
        $stats['quizzes'] = (int)$conn->query("SELECT COUNT(*) FROM quizzes WHERE status = 'published'")->fetchColumn();
        $stats['certificates'] = (int)$conn->query("SELECT COUNT(*) FROM certification_exams WHERE status = 'published'")->fetchColumn();
    }
} catch (Throwable $e) {
    // Use defaults
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['language'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="MyIslam - Your comprehensive Islamic learning platform. Learn Quran, Hadith, Fiqh, and more.">
    <title>MyIslam - Islamic Learning Platform</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --brand: #166534;
            --brand-dark: #14532d;
            --brand-light: #22c55e;
            --brand-soft: #e7f3eb;
            --gold: #b8860b;
            --bg: #f8faf8;
            --surface: #ffffff;
            --text: #1f2937;
            --text-light: #6b7280;
            --line: #e5e7eb;
            --radius: 16px;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background-image:url("includes/back.png") ;
            color: var(--text);
            line-height: 1.6;
            overflow-x: hidden;
        }

        a { text-decoration: none; }
        button { font: inherit; cursor: pointer; }

        /* ===== Navbar ===== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(231, 243, 235, 1.0);
            backdrop-filter: blur(10px);
            border-bottom: 2px solid green;
            padding: 0 24px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .nav-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
            font-weight: 800;
            color: var(--brand);
        }

        .nav-logo img {
            height: 42px;
            width: 42px;
            border-radius: 50%;
            border: 2px solid green;
            object-fit: cover;
            padding: 2px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .nav-links a {
            color: var(--text);
            font-weight: 600;
            font-size: 14px;
            transition: color 0.2s;
        }

        .nav-links a:hover { color: var(--brand); }

        .lang-btn {
            padding: 8px 14px;
            border-radius: 8px;
            background: var(--surface);
            border: 1px solid green;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            color: var(--text);
            transition: all 0.2s;
        }

        .lang-btn:hover {
            background: var(--brand-soft);
            color: var(--brand);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            border: none;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--brand);
            color: white;
            border: 1px solid green;
        }

        .btn-primary:hover {
            background: var(--brand-dark);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--brand);
            border: 1px solid green;
        }

        .btn-outline:hover {
            background: var(--brand-soft);
        }

        .btn-gold {
            background: var(--gold);
            color: white;
            border: 1px solid var(--gold);
            padding: 14px 28px;
            font-size: 16px;
        }

        .btn-gold:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
        }

        /* ===== Hero ===== */
        .hero {
            min-height: 100vh;
           
            align-items: center;
            justify-content: center;
            text-align: center;
            display: flex;
            flex-direction: column;
            padding: 10px 10px 10px;
            background: rgba(255,255,255,0.8);
            position: relative;
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='100' fill='%23166534' fill-opacity='0.03' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 1;
            max-width: 1000px;
            margin-top: 0px;
        }
       .hero-badge {
            display: inline-block;
            background: var(--brand);
            color: white;
            padding: 6px 10px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 10px;
            border: 1px solid green;
        }

        .hero h1 {
            font-size: clamp(32px, 6vw, 56px);
            font-weight: 800;
            color: var(--brand);
            margin-bottom: 16px;
            line-height: 1.2;
        }

        .hero h1 span {
            color: var(--gold);
        }

        .hero p {
            font-size: clamp(16px, 3vw, 20px);
            color: var(--text-light);
            font-weight: 700;
            margin-bottom: 20px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ===== Stats ===== */
        .stats-section {
            padding: 60px 24px;
            text-align: center;
            background: rgba(255,255,255,0.8);
          
            border: 1px solid green;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            max-width: 900px;
            margin: 0 auto;
            border: 2px solid green;
            border-radius: 10px;
        }

        .stat-item {
            padding: 20px;
        }

        .stat-number {
            font-size: 40px;
            font-weight: 800;
            color: var(--brand);
        }

        .stat-label {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-light);
            margin-top: 4px;
        }

        /* ===== Features ===== */
        .features-section {
            padding: 80px 24px;
            max-width: 1100px;
            margin: 0 auto;
            background:  rgba(255,255,255,0.8);
             border: 1px solid green;
             border-radius: 10px;
        }

        .section-title {
            text-align: center;
            font-size: 32px;
            font-weight: 800;
            color: var(--brand);
            margin-bottom: 12px;
        }

        .section-subtitle {
            text-align: center;
            color: var(--text-light);
            font-weight: 700;
            margin-bottom: 48px;
            font-size: 16px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        }

        .feature-card {
            background:  rgba(255,255,255,0.6);
            border: 1px solid green;
            border-radius: var(--radius);
            padding: 28px;
            transition: all 0.2s;
        }

        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }

        .feature-icon {
            font-size: 40px;
            margin-bottom: 16px;
        }

        .feature-card h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .feature-card p {
            color: var(--text-light);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.7;
        }

        /* ===== About ===== */
        .about-section {
            padding: 80px 24px;
            background: rgba(231, 243, 235, 0.9);
            text-align: center;
            border-top: 1px solid green;
            border-bottom: 1px solid green;
        }

        .about-content {
            max-width: 700px;
            margin: 0 auto;
        }

        .about-content h2 {
            font-size: 28px;
            color: var(--brand);
            margin-bottom: 16px;
        }

        .about-content p {
            color: var(--text-light);
            font-size: 16px;
            font-weight: 700;
            line-height: 1.8;
        }

        .full-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-light);
            margin-top: 16px;
            font-style: italic;
        }

        /* ===== Footer ===== */
        .footer {
            background: var(--brand-dark);
            color: white;
            text-align: center;
            padding: 40px 24px;
        }

        .footer p {
            margin-bottom: 8px;
            font-size: 14px;
            opacity: 0.9;
        }

        .footer small {
            opacity: 0.7;
            font-size: 12px;
        }

        /* ===== Responsive ===== */
        @media (max-width: 768px) {
            .nav-links .hide-mobile { display: none; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .hero h1 { font-size: 28px; }
            .section-title { font-size: 24px; }
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar">
    <a href="index.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
    <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid green;object-fit:cover;padding:2px;">
    <span style="font-size:30px;font-weight:1000;color:var(--brand);">MyIslam</span>
</a>
       
        <div class="nav-links">
            <a href="#features" class="hide-mobile"><?php echo lt('nav_features'); ?></a>
            <a href="#about" class="hide-mobile"><?php echo lt('nav_about'); ?></a>
            <button class="lang-btn" id="langToggle"><?php echo lt('language'); ?></button>
            <a href="login.php" class="btn btn-outline"><?php echo lt('login'); ?></a>
            <a href="register.php" class="btn btn-primary"><?php echo lt('join_now'); ?></a>
        </div>
    </nav>

    <!-- Hero -->
   
    <section class="hero">
         <div class="hero-badge"> <?php echo lt('platform_title'); ?></div>
          <div class ="hero-logo">
         <a href="index.php" class="brand-box" style="display:flex;align-items:center;gap:4px; margin-bottom: 10px;">
    <img src="includes/logo.png" alt="MyIslam" style="height:120px;width:120px;border-radius:50%;border:2px solid green;object-fit:cover;padding:2px;">
    
</a></div>
        <div class="hero-content">
            
            <h1><?php echo lt('hero_title1'); ?> <span><?php echo lt('hero_title2'); ?></span></h1>
            <p><?php echo lt('hero_desc'); ?></p>
            <div class="hero-buttons">
                <a href="register.php" class="btn btn-gold">🚀 <?php echo lt('get_started'); ?></a>
                <a href="#features" class="btn btn-outline"><?php echo lt('learn_more'); ?> ↓</a>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section class="stats-section">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['students']; ?>+</div>
                <div class="stat-label"><?php echo lt('active_students'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['subjects']; ?>+</div>
                <div class="stat-label"><?php echo lt('subjects'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['quizzes']; ?>+</div>
                <div class="stat-label"><?php echo lt('quizzes'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['certificates']; ?>+</div>
                <div class="stat-label"><?php echo lt('certifications'); ?></div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="features-section" id="features">
        <h2 class="section-title">📚 <?php echo lt('what_we_offer'); ?></h2>
        <p class="section-subtitle"><?php echo lt('what_we_offer_desc'); ?></p>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">📖</div>
                <h3><?php echo lt('feature1_title'); ?></h3>
                <p><?php echo lt('feature1_desc'); ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📝</div>
                <h3><?php echo lt('feature2_title'); ?></h3>
                <p><?php echo lt('feature2_desc'); ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🏆</div>
                <h3><?php echo lt('feature3_title'); ?></h3>
                <p><?php echo lt('feature3_desc'); ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🎓</div>
                <h3><?php echo lt('feature4_title'); ?></h3>
                <p><?php echo lt('feature4_desc'); ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3><?php echo lt('feature5_title'); ?></h3>
                <p><?php echo lt('feature5_desc'); ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🖼️</div>
                <h3><?php echo lt('feature6_title'); ?></h3>
                <p><?php echo lt('feature6_desc'); ?></p>
            </div>
        </div>
    </section>

    <!-- About -->
    <section class="about-section" id="about">
        <div class="about-content">
            <h2>🕌 <?php echo lt('about_title'); ?></h2>
            <p><?php echo lt('about_desc1'); ?></p>
            <p style="margin-top:16px;"><?php echo lt('about_desc2'); ?></p>
            <div class="full-name"><?php echo lt('full_name'); ?></div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <p><strong><?php echo lt('footer_name'); ?></strong></p>
        <p><?php echo lt('footer_location'); ?></p>
        <small>&copy; <?php echo date('Y'); ?> MyIslam. <?php echo lt('footer_rights'); ?></small>
    </footer>

    <script>
        // Language toggle
        const langToggle = document.getElementById('langToggle');
        let currentLang = '<?php echo $_SESSION['language'] ?? "en"; ?>';
        
        if (langToggle) {
            langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
            langToggle.addEventListener('click', async () => {
                currentLang = currentLang === 'en' ? 'sw' : 'en';
                langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
                
                try {
                    await fetch('ajax/switch_language.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ language: currentLang })
                    });
                    location.reload();
                } catch (e) {
                    console.error('Language switch error:', e);
                }
            });
        }

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>

</body>
</html>