(function ($) {

    $(document).ready(
        function () {
            tinymce.PluginManager.add(
                'cartflows_ac', function (editor, url) {

                    editor.addButton(
                        'cartflows_ac', {
                            type: 'menubutton',
                            text: 'CartFlows Field',
                            icon: false,
                            menu: [
                                {
                                    text: 'Admin Firstname',
                                    value: '{{admin.firstname}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Admin Company',
                                    value: '{{admin.company}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Abandoned Product Details Table',
                                    value: '{{cart.product.table}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Abandoned Product Names',
                                    value: '{{cart.product.names}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Cart Checkout URL',
                                    value: '{{cart.checkout_url}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Coupon Code',
                                    value: '{{cart.coupon_code}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Customer First Name',
                                    value: '{{customer.firstname}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Customer Last Name',
                                    value: '{{customer.lastname}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Customer Full Name',
                                    value: '{{customer.fullname}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Cart Abandonment Date',
                                    value: '{{cart.abandoned_date}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Site URL',
                                    value: '{{site.url}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                                {
                                    text: 'Unsubscribe Link',
                                    value: '{{cart.unsubscribe}}',
                                    onclick: function () {
                                        editor.insertContent(this.value());
                                    }
                                },
                            ].sort(function(a,b){
                                return a.text.localeCompare(b.text);
                            })
                        }
                    );
                }
            );
        }
    );

})(jQuery);

