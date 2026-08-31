</div> <footer class="text-center text-muted mt-5 py-3 border-top bg-white">
    <small><?php echo e('footer.copyright', ['year' => date("Y")]); ?></small>
</footer>

<script src="./assets/bootstrap/bootstrap.bundle.min.js"></script>
<?php if (function_exists('dbg_render')) dbg_render(); // แผงเวลา (แสดงเฉพาะเมื่อ APP_DEBUG=1) ?>
</body>
</html>