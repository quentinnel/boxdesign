define([
    'underscore',
    'Magento_Ui/js/form/element/ui-select',
], function (_, UISelect) {
    return UISelect.extend({
        defaults: {
            NamePrefix: ""
        },
        /**
         * Get name
         *
         * @returns {String} name
        */
         getNamePrefix: function () {
                return this.NamePrefix;
        },
        /**
         * Check selected elements
         *
         * @returns {Boolean}
         */
         hasData: function () {
            console.log("hasData: " + this.value());
            if (!this.value()) {
                this.value([]);
            }

            return this.value() ? !!this.value().length : false;
        },
        /**
         * Get selected element labels
         *
         * @returns {Array} array labels
         */
         getSelected: function () {
            var selected = this.value();
            console.log("selected array: " + selected);
            var testdata = this.cacheOptions.plain.filter(function (opt) {
                console.log("opt.value 5: " + _.contains(selected, opt.value));
                return _.isArray(selected) ? _.contains(selected, opt.value) : selected == opt.value;
            });
            console.log("selected data: " + testdata);
            return this.cacheOptions.plain.filter(function (opt) {
                return _.isArray(selected) ?
                    _.contains(selected, opt.value) :
                selected == opt.value;//eslint-disable-line eqeqeq
            });
        },
    });
});