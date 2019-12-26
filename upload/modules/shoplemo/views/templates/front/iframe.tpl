{extends "$layout"}
{block name="content"}
  <section>
  {if ($shoplemo['status'] == 'success')}
  <form></form>
    <script src="https://payment.shoplemo.com/assets/js/shoplemo.js"></script>
    <iframe src="{$shoplemo['url']}" id="shoplemoiframe" frameborder="0" scrolling="no" style="width: 100%;" width="900" height="400"></iframe>
    <script type="text/javascript">
        setTimeout(function(){ 
            iFrameResize({ log: true },'#shoplemoiframe');
        }, 1000);
    </script>
  </section>
  {else}
    {foreach $shoplemo['details'] as $detail}
       - {$detail} <br />
    {/foreach}
  {/if}
{/block}