<?php get_header(); ?>
<div style="text-align:center; padding:140px 48px; background:var(--fh-bg);">
    <div style="font-family:var(--fh-serif); font-size:100px; font-weight:700; color:var(--fh-bg-lt); line-height:1;">404</div>
    <h1 style="font-size:28px; font-weight:900; text-transform:uppercase; letter-spacing:3px; color:var(--fh-text-1); margin-bottom:16px;">Room Not Found</h1>
    <p style="font-size:14px; color:var(--fh-text-3); margin-bottom:40px;">This fish has checked out. Try heading back to the lobby.</p>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="fh-btn fh-btn--gold">Back to The FisHotel</a>
</div>
<?php get_footer(); ?>
