<style>
    /* Basic Footer Styling */
    .racc-footer {
        background-color: #f8f9fa;
        color: #333;
        padding: 40px 5% 20px;
        margin-top: 50px;
        border-top: 1px solid #eaeaea;
    }
    .racc-footer-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 30px;
        margin-bottom: 30px;
    }
    .racc-footer-col h4 {
        color: #004aad;
        margin-top: 0;
        margin-bottom: 15px;
    }
    .racc-footer-col p, .racc-footer-col a {
        color: #555;
        font-size: 14px;
        text-decoration: none;
        line-height: 1.6;
    }
    .racc-footer-col a:hover {
        color: #004aad;
    }
    .racc-footer-bottom {
        border-top: 1px solid #ddd;
        padding-top: 20px;
        text-align: center;
        font-size: 13px;
        color: #777;
    }
</style>

<footer class="racc-footer">
    <div class="racc-footer-grid">
        <div class="racc-footer-col">
            <h4>About RACC</h4>
            <p>RACC is an award-winning Education and Migration Agency in Australia helping students and professionals achieve their dreams.</p>
        </div>
        <div class="racc-footer-col">
            <h4>Quick Links</h4>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <a href="/about">About Us</a>
                <a href="/migration-services">Migration Services</a>
                <a href="/student-visa">Student Visa</a>
                <a href="/contact">Contact</a>
            </div>
        </div>
        <div class="racc-footer-col">
            <h4>Contact Us</h4>
            <p>Email: info@racc.net.au<br>
            Phone: +61 3 9642 2387<br>
            Location: Melbourne, VIC</p>
        </div>
    </div>
    <div class="racc-footer-bottom">
        &copy; <?php echo date('Y'); ?> RACC Australia. All rights reserved. 
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
