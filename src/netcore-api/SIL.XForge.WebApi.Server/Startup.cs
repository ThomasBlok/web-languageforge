using Hangfire;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.IdentityModel.Tokens;
using Newtonsoft.Json.Serialization;
using SIL.XForge.WebApi.Server.DataAccess;
using SIL.XForge.WebApi.Server.Services;
using System.Collections.Generic;
using System.IO;
using System.Text;

namespace SIL.XForge.WebApi.Server
{
    public class Startup
    {
        public Startup(IConfiguration configuration, IHostingEnvironment env)
        {
            Configuration = configuration;
            Environment = env;
        }

        public IConfiguration Configuration { get; }
        public IHostingEnvironment Environment { get; }

        // This method gets called by the runtime. Use this method to add services to the container.
        public void ConfigureServices(IServiceCollection services)
        {
            var issuers = new List<string> { "languageforge.org", "scriptureforge.org" };
            if (Environment.IsDevelopment())
            {
                issuers.Add("languageforge.local");
                issuers.Add("scriptureforge.local");
            }
            IConfigurationSection securityConfig = Configuration.GetSection("Security");
            string keyFilePath = securityConfig.GetValue<string>("JwtKeyFile");
            string key = "this_is_not_a_secret_dev_only";
            if (!string.IsNullOrEmpty(keyFilePath) && File.Exists(keyFilePath))
                key = File.ReadAllText(keyFilePath).Trim();
            services.AddAuthentication(JwtBearerDefaults.AuthenticationScheme)
                .AddJwtBearer(options =>
                {
                    options.TokenValidationParameters = new TokenValidationParameters
                    {
                        ValidIssuers = issuers,
                        ValidAudiences = issuers,
                        RequireExpirationTime = false,
                        IssuerSigningKey = new SymmetricSecurityKey(Encoding.ASCII.GetBytes(key))
                    };
                });

            services.AddCors(options =>
            {
                options.AddPolicy("GlobalPolicy", policy => policy
                    .AllowAnyOrigin()
                    .AllowAnyMethod()
                    .AllowAnyHeader()
                    .AllowCredentials());
            });

            services.AddMvc()
                .AddJsonOptions(a => a.SerializerSettings.ContractResolver
                    = new CamelCasePropertyNamesContractResolver());
            services.AddRouting(options => options.LowercaseUrls = true);

            services.AddMongoDataAccess(Configuration);
            services.AddSingleton<SendReceiveService>();
        }

        // This method gets called by the runtime. Use this method to configure the HTTP request pipeline.
        public void Configure(IApplicationBuilder app, IHostingEnvironment env)
        {
            if (env.IsDevelopment())
                app.UseDeveloperExceptionPage();

            app.UseAuthentication();

            app.UseCors("GlobalPolicy");

            app.UseMvc();

            app.UseHangfireServer();
        }
    }
}
